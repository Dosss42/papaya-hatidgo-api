<?php

namespace Tests\Feature\Notifications;

use App\Enums\DocumentStatus;
use App\Enums\SubscriptionState;
use App\Models\DriverDocument;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rides\RideHelpers;
use Tests\TestCase;

/** Phase 13 decision #8: papers and subscriptions reminded at the right time, each reminder ONCE (N7). */
class ReminderTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    private function remind(): void
    {
        $this->artisan('notifications:reminders')->assertSuccessful();
    }

    /** @return list<string> the kinds this user received, oldest first */
    private function kinds(User $user): array
    {
        return $user->notifications()->reorder('created_at')->pluck('type')->all();
    }

    private function license(User $driver): DriverDocument
    {
        return DriverDocument::where('driver_id', $this->driverOf($driver)->id)
            ->whereHas('requirement', fn ($q) => $q->where('code', 'drivers_license'))->firstOrFail();
    }

    public function test_a_paper_is_reminded_at_30_and_at_7_days_once_each(): void
    {
        $driver = $this->driver();
        $this->license($driver)->forceFill(['expires_at' => now()->addDays(20)->toDateString()])->save();

        $this->remind();
        $this->remind(); // the next hour: nothing new
        $this->assertSame(['document_expiring'], $this->kinds($driver));

        $this->travel(15)->days(); // 5 days left
        $this->remind();
        $this->remind();
        $this->assertSame(['document_expiring', 'document_expiring'], $this->kinds($driver));
        $this->assertSame([30, 7], $driver->notifications()->reorder('created_at')->get()->map(fn ($n) => $n->data['ref']['window'])->all());
    }

    public function test_no_reminder_once_the_new_paper_was_sent(): void
    {
        $driver = $this->driver();
        $license = $this->license($driver);
        $license->forceFill(['expires_at' => now()->addDays(5)->toDateString()])->save();
        DriverDocument::forceCreate([
            'driver_id' => $license->driver_id, 'driver_requirement_id' => $license->driver_requirement_id,
            'status' => DocumentStatus::Pending, 'is_current' => false, 'expires_at' => now()->addYears(3)->toDateString(),
        ]);

        $this->remind();

        $this->assertSame([], $this->kinds($driver));
    }

    public function test_an_expired_paper_is_told_once_and_old_history_is_left_alone(): void
    {
        $driver = $this->driver(online: false);
        $this->license($driver)->forceFill(['expires_at' => now()->subDay()->toDateString()])->save();
        $old = $this->driver(online: false);
        $this->license($old)->forceFill(['expires_at' => now()->subDays(40)->toDateString(), 'status' => DocumentStatus::Expired])->save();

        $this->remind();
        $this->remind();

        $this->assertSame(['document_expired'], $this->kinds($driver));
        $this->assertSame([], $this->kinds($old));
    }

    public function test_a_subscription_ending_soon_and_ended_is_told_once_each_unless_renewed(): void
    {
        $user = $this->passenger(subscribed: false);
        $plan = SubscriptionPlan::where('code', 'pax_1m')->firstOrFail();
        Subscription::forceCreate(['user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'state' => SubscriptionState::Paid,
            'amount' => $plan->price, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(2)]);

        $this->remind();
        $this->remind();
        $this->assertSame(['subscription_ending'], $this->kinds($user));

        $this->travel(3)->days();
        $this->remind();
        $this->remind();
        $this->assertSame(['subscription_ending', 'subscription_ended'], $this->kinds($user));

        // Renewed early: no "ending" reminder at all.
        $renewed = $this->passenger(subscribed: false);
        $ends = now()->addDays(2);
        Subscription::forceCreate(['user_id' => $renewed->id, 'subscription_plan_id' => $plan->id, 'state' => SubscriptionState::Paid,
            'amount' => $plan->price, 'starts_at' => now()->subMonth(), 'ends_at' => $ends]);
        Subscription::forceCreate(['user_id' => $renewed->id, 'subscription_plan_id' => $plan->id, 'state' => SubscriptionState::Paid,
            'amount' => $plan->price, 'starts_at' => $ends, 'ends_at' => $ends->copy()->addMonth()]);
        $this->remind();
        $this->assertSame([], $this->kinds($renewed));
    }
}
