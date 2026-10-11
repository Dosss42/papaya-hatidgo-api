<?php

namespace Tests\Feature\Rides;

use App\Enums\DocumentStatus;
use App\Enums\SubscriptionState;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\Passenger;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DriverRequirementSeeder;
use Database\Seeders\FareSettingsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Shared setup for the Phase 9 ride tests. Coordinates are around the (placeholder) service-area
 * center 15.35, 121.05: 0.01° of latitude ≈ 1.11 km.
 */
trait RideHelpers
{
    protected const LAT = 15.35;

    protected const LNG = 121.05;

    protected function seedRideWorld(): void
    {
        $this->seed([DriverRequirementSeeder::class, SubscriptionPlanSeeder::class, FareSettingsSeeder::class, SystemSettingsSeeder::class]);
    }

    protected function setting(string $key, string $value): void
    {
        SystemSetting::where('key', $key)->update(['value' => $value]);
    }

    protected function subscribe(User $user, string $planCode): void
    {
        $plan = SubscriptionPlan::where('code', $planCode)->firstOrFail();
        Subscription::forceCreate([
            'user_id' => $user->id, 'subscription_plan_id' => $plan->id, 'state' => SubscriptionState::Paid,
            'amount' => $plan->price, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
    }

    protected function passenger(bool $subscribed = true): User
    {
        $user = User::factory()->passenger()->create();
        Passenger::forceCreate(['user_id' => $user->id]);
        if ($subscribed) {
            $this->subscribe($user, 'pax_1m');
        }

        return $user->refresh();
    }

    /**
     * A driver who passes every go-online check and is online at ($lat, $lng) right now.
     * Pass false flags to build the ineligible cases (MATCH-01).
     */
    protected function driver(float $lat = self::LAT, float $lng = self::LNG, bool $online = true, bool $subscribed = true, bool $verified = true): User
    {
        $user = User::factory()->driver()->create();
        $driver = Driver::forceCreate(['user_id' => $user->id]);
        $vehicle = Vehicle::forceCreate(['driver_id' => $driver->id, 'plate_number' => 'ABC'.fake()->unique()->numerify('####'), 'color' => 'Pula', 'body_number' => (string) fake()->unique()->numberBetween(1, 9999)]); // UNIQUE in the database
        $driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();

        if ($verified) {
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
            app(\App\Services\DriverComplianceService::class)->recalculate($driver); // verified + tricycle verified
        }
        if ($subscribed) {
            $this->subscribe($user, 'drv_1m');
        }
        $driver->refresh()->forceFill([
            'is_online' => $online,
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => now(),
        ])->save();

        return $user->refresh();
    }

    protected function driverOf(User $user): Driver
    {
        return Driver::where('user_id', $user->id)->firstOrFail();
    }

    /** A trip of about 2.2 km north of the center. */
    protected function trip(array $extra = []): array
    {
        return array_merge([
            'pickup_lat' => self::LAT, 'pickup_lng' => self::LNG, 'pickup_landmark' => 'Harap ng munisipyo',
            'destination_lat' => self::LAT + 0.02, 'destination_lng' => self::LNG, 'destination_landmark' => 'Palengke',
            'ride_type' => 'one_way',
        ], $extra);
    }

    protected function book(User $passenger, array $extra = []): TestResponse
    {
        Sanctum::actingAs($passenger);

        return $this->postJson('/api/v1/rides', $this->trip($extra));
    }

    protected function bookId(User $passenger, array $extra = []): int
    {
        return $this->book($passenger, $extra)->assertCreated()->json('data.id');
    }

    /** As the driver, call POST /rides/{id}/{action}. */
    protected function as(User $driver, int $ride, string $action, array $body = []): TestResponse
    {
        Sanctum::actingAs($driver);

        return $this->postJson("/api/v1/rides/{$ride}/{$action}", $body);
    }

    protected function statusOf(int $ride): string
    {
        return \App\Models\RideRequest::findOrFail($ride)->status->value;
    }
}
