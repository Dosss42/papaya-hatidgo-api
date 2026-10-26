<?php

namespace Tests\Feature\Notifications;

use App\Enums\DocumentStatus;
use App\Enums\SubscriptionState;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Rides\RideHelpers;
use Tests\TestCase;

/**
 * Phase 13: every § J event reaches the right person, by the right way (decision #4):
 * the Alerts list + push for outcomes, push only for live ride steps and requests.
 * Notification::fake() records what would be sent without sending anything.
 */
class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
        Notification::fake();
    }

    /** Was a notification of this kind sent to this user, by exactly these channels? */
    private function assertKind(User $user, string $kind, array $channels, ?callable $check = null): void
    {
        Notification::assertSentTo($user, AppNotification::class,
            fn (AppNotification $n, array $via) => $n->kind === $kind && $via === $channels && (! $check || $check($n)));
    }

    private function assertNoKind(User $user, string $kind): void
    {
        Notification::assertNotSentTo($user, AppNotification::class, fn (AppNotification $n) => $n->kind === $kind);
    }

    public function test_a_new_request_rings_only_the_drivers_it_was_offered_to(): void
    {
        $near = $this->driver();
        $far = $this->driver(self::LAT + 0.2); // ~22 km: outside the matching radius

        $ride = $this->bookId($this->passenger());

        $this->assertKind($near, 'ride_offer', [FcmChannel::class], fn (AppNotification $n) => $n->channel === AppNotification::RIDES
            && $n->tag === "ride-{$ride}" && $n->ttlSeconds > 0 && $n->ttlSeconds <= 120 && $n->params['fare'] === '52.20');
        $this->assertNoKind($far, 'ride_offer');
    }

    public function test_the_passenger_hears_each_step_of_the_ride_by_push_and_keeps_the_outcome(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);

        $this->as($driver, $ride, 'accept')->assertOk();
        $this->assertKind($passenger, 'ride_accepted', [FcmChannel::class], fn (AppNotification $n) => $n->target === "ride:{$ride}"
            && $n->params['driver'] === $driver->first_name && isset($n->params['plate']));

        $this->as($driver, $ride, 'depart')->assertOk();
        $this->assertKind($passenger, 'ride_arriving', [FcmChannel::class]);
        $this->as($driver, $ride, 'arrive')->assertOk();
        $this->assertKind($passenger, 'ride_arrived', [FcmChannel::class]);
        $this->as($driver, $ride, 'start')->assertOk(); // no push: the passenger is in the tricycle
        $this->as($driver, $ride, 'complete')->assertOk();

        $this->assertKind($passenger, 'ride_completed', ['database', FcmChannel::class], fn (AppNotification $n) => $n->params['total'] === '52.20');
        $this->assertNoKind($passenger, 'ride_started');
        $this->assertNoKind($driver, 'ride_completed'); // the driver pressed it themselves
    }

    public function test_a_cancel_tells_the_other_party_with_the_reason(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);
        $this->as($driver, $ride, 'accept')->assertOk();

        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/cancel", ['reason' => 'changed_plans'])->assertOk();

        $this->assertKind($driver, 'ride_cancelled_by_passenger', ['database', FcmChannel::class],
            fn (AppNotification $n) => $n->params['reason'] === 'changed_plans');
        $this->assertNoKind($passenger, 'ride_cancelled_by_passenger');
    }

    public function test_a_driver_cancel_tells_the_passenger_and_an_admin_cancel_tells_both(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);
        $this->as($driver, $ride, 'accept')->assertOk();
        $this->as($driver, $ride, 'cancel', ['reason' => 'vehicle_problem'])->assertOk();
        $this->assertKind($passenger, 'ride_cancelled_by_driver', ['database', FcmChannel::class]);

        $driver2 = $this->driver();
        $ride2 = $this->bookId($passenger);
        $this->as($driver2, $ride2, 'accept')->assertOk();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/v1/admin/rides/{$ride2}/cancel", ['reason' => 'Reported unsafe pickup'])->assertOk();

        $this->assertKind($passenger, 'ride_cancelled_by_admin', ['database', FcmChannel::class]);
        $this->assertKind($driver2, 'ride_cancelled_by_admin', ['database', FcmChannel::class],
            fn (AppNotification $n) => $n->params['reason'] === 'Reported unsafe pickup');
    }

    public function test_no_driver_found_is_told_once(): void
    {
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger); // nobody online

        $this->travel(3)->minutes();
        $this->artisan('rides:expire')->assertSuccessful();
        $this->artisan('rides:expire')->assertSuccessful();

        Notification::assertSentToTimes($passenger, AppNotification::class, 1);
        $this->assertKind($passenger, 'ride_no_driver', ['database', FcmChannel::class], fn (AppNotification $n) => $n->target === "ride:{$ride}");
    }

    public function test_the_waiting_driver_hears_that_the_passenger_is_coming_back(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger, ['ride_type' => 'two_way', 'wait_minutes' => 30]);
        foreach (['accept', 'depart', 'arrive', 'start', 'reach-destination'] as $step) {
            $this->as($driver, $ride, $step)->assertOk();
        }

        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/passenger-returning")->assertOk();

        $this->assertKind($driver, 'passenger_returning', [FcmChannel::class]);
    }

    public function test_paper_decisions_reach_the_driver_with_the_reason_and_a_link_to_the_paper(): void
    {
        $driver = $this->driver();
        $license = DriverRequirement::where('code', 'drivers_license')->firstOrFail();
        $renewal = fn () => DriverDocument::forceCreate([
            'driver_id' => $this->driverOf($driver)->id, 'driver_requirement_id' => $license->id,
            'status' => DocumentStatus::Pending, 'is_current' => false, 'expires_at' => now()->addYears(2)->toDateString(),
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/driver-documents/'.$renewal()->id.'/reject', ['reason' => 'Malabo ang litrato'])->assertOk();
        $this->assertKind($driver, 'document_rejected', ['database', FcmChannel::class], fn (AppNotification $n) => $n->params['reason'] === 'Malabo ang litrato'
            && $n->params['requirement'] === 'drivers_license' && $n->target === 'requirement:drivers_license');
        $this->assertNoKind($driver, 'driver_offline_papers'); // a rejected renewal leaves the current paper valid

        $this->postJson('/api/v1/admin/driver-documents/'.$renewal()->id.'/approve', ['expires_at' => now()->addYears(2)->toDateString()])->assertOk();
        $this->assertKind($driver, 'document_approved', ['database', FcmChannel::class]);
    }

    public function test_a_driver_set_offline_by_the_server_is_told_why(): void
    {
        $stale = $this->driver();
        $this->driverOf($stale)->forceFill(['location_updated_at' => now()->subMinutes(10)])->save();
        $fresh = $this->driver();

        $this->artisan('drivers:offline-stale')->assertSuccessful();

        $this->assertKind($stale, 'driver_offline_stale', ['database', FcmChannel::class], fn (AppNotification $n) => $n->target === 'home');
        $this->assertNoKind($fresh, 'driver_offline_stale');

        // Papers: a critical paper past its date sets an ONLINE driver offline (and says so).
        $papers = $this->driver();
        DriverDocument::where('driver_id', $this->driverOf($papers)->id)->firstOrFail()
            ->forceFill(['expires_at' => now()->subDays(2)->toDateString()])->save();
        $this->artisan('documents:expire')->assertSuccessful();

        $this->assertKind($papers, 'driver_offline_papers', ['database', FcmChannel::class], fn (AppNotification $n) => $n->target === 'requirements');
    }

    public function test_a_failing_notification_never_fails_the_ride_step_it_reports(): void
    {
        $driver = $this->driver();
        $ride = $this->bookId($this->passenger());
        $this->as($driver, $ride, 'accept')->assertOk();

        // The notification system breaks (e.g. the notifications table is unreachable).
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('notifications are down'));

        $this->as($driver, $ride, 'depart')->assertOk()->assertJsonPath('data.status', 'driver_arriving');
        $this->assertSame('driver_arriving', $this->statusOf($ride));
    }

    public function test_an_activated_subscription_is_announced_once(): void
    {
        $user = $this->passenger(subscribed: false);
        $plan = SubscriptionPlan::where('code', 'pax_1m')->firstOrFail();
        $subscription = Subscription::forceCreate(['user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'state' => SubscriptionState::Pending, 'amount' => $plan->price]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/subscriptions/{$subscription->id}/activate", ['reason' => 'Paid in cash at the office'])->assertOk();
        $this->postJson("/api/v1/admin/subscriptions/{$subscription->id}/activate", ['reason' => 'Again'])->assertStatus(409);

        Notification::assertSentToTimes($user, AppNotification::class, 1);
        $this->assertKind($user, 'subscription_activated', ['database', FcmChannel::class], fn (AppNotification $n) => $n->target === 'subscription');
    }
}
