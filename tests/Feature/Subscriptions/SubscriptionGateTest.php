<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\DocumentStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Payments\FakeGateway;
use Database\Seeders\DriverRequirementSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The gates (Phase 8 step 8.4, phase-0 § F.1): drivers go online only when every go-online check
 * passes (subscription included); a `subscribed` route refuses users without an active subscription.
 */
class SubscriptionGateTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([DriverRequirementSeeder::class, SubscriptionPlanSeeder::class]);
        config(['payments.gateway' => 'fake']);
    }

    /** A driver whose tricycle and 4 papers are all approved: only the subscription is missing. */
    private function verifiedDriver(): User
    {
        $user = $this->driverUser();
        $driver = Driver::where('user_id', $user->id)->firstOrFail();
        $vehicle = Vehicle::forceCreate(['driver_id' => $driver->id, 'plate_number' => 'ABC1234', 'color' => 'Pula']);
        $driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();

        foreach (DriverRequirement::all() as $requirement) {
            DriverDocument::forceCreate([
                'driver_id' => $driver->id,
                'driver_requirement_id' => $requirement->id,
                'vehicle_id' => in_array($requirement->code, ['or_cr', 'mtop_permit'], true) ? $vehicle->id : null,
                'status' => DocumentStatus::Approved,
                'is_current' => true,
                'expires_at' => now()->addYear()->toDateString(),
            ]);
        }

        return $user;
    }

    private function subscribe(User $user, string $plan): void
    {
        [$id, $checkoutId] = $this->checkout($user, $plan);
        FakeGateway::markPaid($checkoutId, 'gcash');
        $this->getJson("/api/v1/subscriptions/{$id}")->assertJsonPath('data.status', 'active');
    }

    private function check(array $checks, string $key): bool
    {
        return collect($checks)->firstWhere('key', $key)['passed'];
    }

    public function test_a_verified_driver_without_a_subscription_cannot_go_online(): void
    {
        Sanctum::actingAs($this->verifiedDriver());

        $eligibility = $this->getJson('/api/v1/drivers/me/eligibility')->assertOk()->json('data');
        $this->assertFalse($eligibility['eligible']);
        $this->assertTrue($this->check($eligibility['checks'], 'compliance_verified'));
        $this->assertFalse($this->check($eligibility['checks'], 'subscription_active'));

        $this->patchJson('/api/v1/drivers/me/availability', ['is_online' => true])
            ->assertForbidden()
            ->assertJsonPath('code', 'NOT_ELIGIBLE')
            ->assertJsonPath('data.eligible', false);
        $this->assertDatabaseHas('drivers', ['user_id' => auth()->id(), 'is_online' => false]);
    }

    public function test_after_paying_the_driver_can_go_online_and_back_offline(): void
    {
        $user = $this->verifiedDriver();
        $this->subscribe($user, 'drv_1m');

        $this->getJson('/api/v1/drivers/me/eligibility')->assertJsonPath('data.eligible', true);
        $this->patchJson('/api/v1/drivers/me/availability', ['is_online' => true])
            ->assertOk()->assertJsonPath('data.is_online', true);
        $this->patchJson('/api/v1/drivers/me/availability', ['is_online' => false])
            ->assertOk()->assertJsonPath('data.is_online', false);
    }

    public function test_when_the_subscription_ends_going_online_is_refused_again(): void
    {
        $user = $this->verifiedDriver();
        $this->subscribe($user, 'drv_1m');

        $this->travel(32)->days(); // past ends_at (grace days = 0)
        $this->patchJson('/api/v1/drivers/me/availability', ['is_online' => true])->assertForbidden();
        $this->getJson('/api/v1/subscriptions/current')->assertJsonPath('data.status', 'expired');
    }

    public function test_going_offline_is_always_allowed_and_input_is_validated(): void
    {
        Sanctum::actingAs($this->driverUser()); // nothing done yet

        $this->patchJson('/api/v1/drivers/me/availability', ['is_online' => false])->assertOk();
        $this->patchJson('/api/v1/drivers/me/availability', [])->assertStatus(422);
    }

    public function test_passengers_have_no_go_online_endpoints(): void
    {
        Sanctum::actingAs($this->passengerUser());

        $this->getJson('/api/v1/drivers/me/eligibility')->assertForbidden();
        $this->patchJson('/api/v1/drivers/me/availability', ['is_online' => true])->assertForbidden();
    }

    public function test_the_subscribed_middleware_guards_a_booking_route(): void
    {
        // The real booking route (POST /rides) arrives in Phase 9; a stand-in with the same middleware.
        Route::middleware(['api', 'auth:sanctum', 'subscribed'])->post('/api/v1/_test/rides', fn () => ['booked' => true]);
        $passenger = $this->passengerUser();
        Sanctum::actingAs($passenger);

        $this->postJson('/api/v1/_test/rides')->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');

        $this->subscribe($passenger, 'pax_1m');
        $this->postJson('/api/v1/_test/rides')->assertOk()->assertJsonPath('booked', true);

        $this->travel(32)->days();
        $this->postJson('/api/v1/_test/rides')->assertForbidden();
    }
}
