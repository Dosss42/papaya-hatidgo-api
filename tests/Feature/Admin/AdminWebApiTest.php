<?php

namespace Tests\Feature\Admin;

use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\FareSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Rides\RideHelpers;
use Tests\TestCase;

/** The admin website's API (Phase 14 step 14.1): every screen's endpoint, and its audit trail. */
class AdminWebApiTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
        $this->admin = User::factory()->admin()->create();
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    /** A completed one-way ride (₱52.20 with the seeded fares). */
    private function completedRide(): int
    {
        $driver = $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger());
        foreach (['accept', 'depart', 'arrive', 'start', 'complete'] as $step) {
            $this->as($driver, $ride, $step)->assertOk();
        }

        return $ride;
    }

    public function test_only_admins_may_use_the_admin_api(): void
    {
        foreach ([$this->passenger(), $this->driver()] as $user) {
            Sanctum::actingAs($user);
            foreach (['dashboard', 'users', 'live', 'fare-settings', 'system-settings', 'subscription-plans', 'audit-logs', 'reports/rides'] as $path) {
                $this->getJson("/api/v1/admin/{$path}")->assertForbidden();
            }
        }
    }

    public function test_the_dashboard_counts_what_happened_today(): void
    {
        $this->completedRide();
        $this->asAdmin();
        $this->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.rides_today.completed', 1)
            ->assertJsonPath('data.fares_today', '52.20')
            ->assertJsonPath('data.drivers_online', 1)
            ->assertJsonPath('data.rides_active', 0)
            ->assertJsonPath('data.subscriptions_active.drivers', 1)
            ->assertJsonPath('data.subscriptions_active.passengers', 1);
    }

    public function test_suspending_a_passenger_blocks_booking_until_reactivated_and_is_audited(): void
    {
        $passenger = $this->passenger();
        $this->asAdmin();
        $this->getJson('/api/v1/admin/users?q='.urlencode($passenger->email))->assertJsonPath('meta.total', 1);
        $this->patchJson("/api/v1/admin/users/{$passenger->id}/status", ['status' => 'suspended'])->assertStatus(422); // a reason is required
        $this->patchJson("/api/v1/admin/users/{$passenger->id}/status", ['status' => 'suspended', 'reason' => 'Paulit-ulit na pag-cancel'])
            ->assertOk()->assertJsonPath('data.account_status', 'suspended');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.suspended', 'auditable_id' => $passenger->id]);

        $this->driver(self::LAT + 0.001);
        // refresh(): a real request loads the user from the database; the test's object is stale.
        $this->book($passenger->refresh())->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->asAdmin();
        $this->patchJson("/api/v1/admin/users/{$passenger->id}/status", ['status' => 'active'])->assertOk();
        $this->book($passenger->refresh())->assertCreated();
    }

    public function test_a_suspended_driver_goes_offline_and_admins_cannot_be_suspended(): void
    {
        $driver = $this->driver();
        $this->asAdmin();
        $this->patchJson("/api/v1/admin/users/{$driver->id}/status", ['status' => 'suspended', 'reason' => 'Reklamo ng pasahero'])
            ->assertOk()->assertJsonPath('data.driver.is_online', false);
        $this->patchJson("/api/v1/admin/users/{$this->admin->id}/status", ['status' => 'suspended', 'reason' => 'test'])
            ->assertStatus(422)->assertJsonPath('code', 'ADMIN_ACCOUNT_LOCKED');
    }

    public function test_a_new_fare_prices_the_next_estimate_and_the_old_one_stays_in_history(): void
    {
        $this->asAdmin();
        $fare = ['base_fare' => 20, 'rate_per_km' => 10, 'minimum_fare' => 30, 'return_rate_multiplier' => 0.8, 'waiting_free_minutes' => 10, 'waiting_fee_per_minute' => 1, 'service_fee' => 0];
        $this->postJson('/api/v1/admin/fare-settings', ['base_fare' => 5000] + $fare)->assertStatus(422); // a typo, refused
        $this->postJson('/api/v1/admin/fare-settings', $fare)->assertCreated();

        $history = $this->getJson('/api/v1/admin/fare-settings')->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('20.00', $history[0]['base_fare']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fare.created']);

        Sanctum::actingAs($this->passenger());
        $estimate = $this->postJson('/api/v1/fare/estimate', ['pickup_lat' => self::LAT, 'pickup_lng' => self::LNG, 'destination_lat' => self::LAT + 0.02, 'destination_lng' => self::LNG])->assertOk();
        $oneWay = collect($estimate->json('data.options'))->firstWhere('ride_type', 'one_way');
        $this->assertSame('20.00', $oneWay['breakdown']['base_fare']);
        $this->assertSame(2, FareSetting::count()); // the old row was not touched
    }

    public function test_settings_are_checked_by_range_all_or_nothing_and_audited(): void
    {
        $this->asAdmin();
        $this->patchJson('/api/v1/admin/system-settings', ['settings' => ['matching.request_timeout_seconds' => 5]])->assertStatus(422);
        $this->patchJson('/api/v1/admin/system-settings', ['settings' => ['matching.radius_km' => 4, 'matching.max_offers' => 99]])->assertStatus(422);
        $this->assertSame('3', SystemSetting::where('key', 'matching.radius_km')->value('value')); // nothing changed
        $this->patchJson('/api/v1/admin/system-settings', ['settings' => ['app.debug' => true]])->assertStatus(422);
        $this->patchJson('/api/v1/admin/system-settings', ['settings' => ['ride.one_way_enabled' => false, 'ride.two_way_enabled' => false]])
            ->assertStatus(422)->assertJsonPath('code', 'RIDE_TYPES_ALL_OFF');

        $this->patchJson('/api/v1/admin/system-settings', ['settings' => ['matching.request_timeout_seconds' => 90, 'ride.two_way_enabled' => false]])->assertOk();
        $this->assertSame('90', SystemSetting::where('key', 'matching.request_timeout_seconds')->value('value'));
        $log = AuditLog::where('action', 'setting.changed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, AuditLog::where('action', 'setting.changed')->count());

        Sanctum::actingAs($this->passenger());
        $this->getJson('/api/v1/booking-options')->assertJsonPath('data.ride_types', ['one_way']); // the app follows at once
    }

    public function test_plans_can_be_added_repriced_and_renamed_but_not_remeaned_once_sold(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/subscription-plans', ['code' => 'drv_3m', 'name' => 'Driver · 3 months', 'user_type' => 'driver', 'duration_months' => 3, 'price' => 499])
            ->assertCreated()->assertJsonPath('data.active_count', 0);

        $sold = SubscriptionPlan::where('code', 'pax_1m')->first();
        Subscription::forceCreate(['user_id' => $this->passenger(false)->id, 'subscription_plan_id' => $sold->id, 'state' => 'paid', 'amount' => $sold->price, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->patchJson("/api/v1/admin/subscription-plans/{$sold->id}", ['duration_months' => 2])->assertStatus(422)->assertJsonPath('code', 'PLAN_IN_USE');
        $this->patchJson("/api/v1/admin/subscription-plans/{$sold->id}", ['price' => 59, 'name' => 'Pasahero · Buwanan'])->assertOk()->assertJsonPath('data.price', '59.00');
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated', 'auditable_id' => $sold->id]);

        Sanctum::actingAs($this->passenger(false));
        $names = collect($this->getJson('/api/v1/subscription-plans')->json('data'))->pluck('name');
        $this->assertContains('Pasahero · Buwanan', $names); // the admin's rename shows in the app, not hidden by a translation
    }

    public function test_requirements_can_be_added_and_switched_off(): void
    {
        $this->asAdmin();
        $id = $this->postJson('/api/v1/admin/driver-requirements', ['code' => 'medical_cert', 'name' => 'Medical certificate', 'applies_to' => 'driver', 'is_critical' => false])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/driver-requirements', ['code' => 'medical_cert', 'name' => 'Again', 'applies_to' => 'driver'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/driver-requirements/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'requirement.updated', 'auditable_id' => $id]);
    }

    public function test_the_live_map_shows_online_drivers_and_active_rides(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $this->driver(self::LAT + 0.003, online: false);
        $ride = $this->bookId($this->passenger());
        $this->as($driver, $ride, 'accept')->assertOk();

        $this->asAdmin();
        $live = $this->getJson('/api/v1/admin/live')->assertOk();
        $live->assertJsonCount(1, 'data.drivers')->assertJsonPath('data.drivers.0.on_ride', true)->assertJsonPath('data.drivers.0.matchable', true);
        $live->assertJsonCount(1, 'data.rides')->assertJsonPath('data.rides.0.status', 'accepted');
        $this->assertEqualsWithDelta(15.35, $live->json('data.service_area.center_lat'), 0.0001);
    }

    public function test_reports_add_up_and_the_range_is_limited(): void
    {
        $this->completedRide();
        $sub = Subscription::first();
        SubscriptionTransaction::forceCreate(['subscription_id' => $sub->id, 'amount' => '99.00', 'status' => TransactionStatus::Paid, 'provider' => 'fake', 'provider_checkout_id' => 'cs_test_1', 'paid_at' => now()]);

        $this->asAdmin();
        $this->getJson('/api/v1/admin/reports/rides')->assertOk()
            ->assertJsonCount(30, 'data.days')
            ->assertJsonPath('data.totals.completed', 1)
            ->assertJsonPath('data.totals.fares', '52.20');
        $this->getJson('/api/v1/admin/reports/revenue')->assertOk()->assertJsonPath('data.totals.amount', '99.00')->assertJsonPath('data.totals.payments', 1);
        $this->getJson('/api/v1/admin/reports/drivers')->assertOk()->assertJsonPath('data.drivers.0.rides', 1)->assertJsonPath('data.drivers.0.earnings', '52.20');
        $this->getJson('/api/v1/admin/reports/rides?from=2026-01-01&to=2026-06-30')->assertStatus(422)->assertJsonPath('code', 'RANGE_TOO_LONG');
        $this->getJson('/api/v1/admin/subscription-transactions?status=paid')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '99.00');
    }

    public function test_the_audit_log_lists_admin_actions_newest_first_and_filters_by_action(): void
    {
        $passenger = $this->passenger();
        $this->asAdmin();
        $this->patchJson("/api/v1/admin/users/{$passenger->id}/status", ['status' => 'suspended', 'reason' => 'Test lang'])->assertOk();
        $this->patchJson('/api/v1/admin/system-settings', ['settings' => ['matching.max_offers' => 7]])->assertOk();

        $all = $this->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->assertSame('setting.changed', $all->json('data.0.action'));
        $this->assertSame($this->admin->id, $all->json('data.0.actor.id'));
        $this->getJson('/api/v1/admin/audit-logs?action=user.')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.new_values.reason', 'Test lang');
    }
}
