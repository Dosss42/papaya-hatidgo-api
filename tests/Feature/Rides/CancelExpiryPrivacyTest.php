<?php

namespace Tests\Feature\Rides;

use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cancel, expiry, privacy and the trail (Phase 9 step 9.4). */
class CancelExpiryPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
        // Each scenario adds a driver at the same spot; with the default 5 offers, a 6th equally
        // near driver might not be offered the new ride. Offer to everyone here.
        $this->setting('matching.max_offers', '50');
    }

    /** A ride moved to $steps by a fresh driver; returns [ride id, passenger, driver]. */
    private function rideAt(array $steps, string $type = 'one_way'): array
    {
        $driver = $this->driver(self::LAT + 0.001);
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger, $type === 'two_way' ? ['ride_type' => 'two_way', 'wait_minutes' => 15] : []);
        foreach ($steps as $step) {
            $this->as($driver, $ride, $step)->assertOk();
        }

        return [$ride, $passenger, $driver];
    }

    public function test_ride_06_the_passenger_can_cancel_until_the_trip_starts(): void
    {
        foreach ([[], ['accept'], ['accept', 'depart'], ['accept', 'depart', 'arrive']] as $steps) {
            [$ride, $passenger] = $this->rideAt($steps);
            Sanctum::actingAs($passenger);
            $this->postJson("/api/v1/rides/{$ride}/cancel", ['reason' => 'Nagbago ang plano'])->assertOk()
                ->assertJsonPath('data.status', 'cancelled')
                ->assertJsonPath('data.cancelled_by', 'passenger')
                ->assertJsonPath('data.cancel_reason', 'Nagbago ang plano');
        }

        [$ride, $passenger] = $this->rideAt([]);
        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/cancel")->assertOk(); // the reason is optional (brief: chips)

        [$started, $passenger] = $this->rideAt(['accept', 'depart', 'arrive', 'start']);
        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$started}/cancel")->assertStatus(409)->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    public function test_the_driver_cancels_only_with_a_reason_and_only_before_the_start(): void
    {
        [$ride, , $driver] = $this->rideAt(['accept', 'depart', 'arrive']);

        $this->as($driver, $ride, 'cancel')->assertStatus(422);
        $this->as($driver, $ride, 'cancel', ['reason' => 'Hindi dumating ang pasahero'])->assertOk()->assertJsonPath('data.cancelled_by', 'driver');

        [$started, , $driver] = $this->rideAt(['accept', 'depart', 'arrive', 'start']);
        $this->as($driver, $started, 'cancel', ['reason' => 'Ayaw ko na'])->assertStatus(409);
    }

    public function test_an_admin_can_cancel_any_active_ride_and_it_is_audited(): void
    {
        [$ride] = $this->rideAt(['accept', 'depart', 'arrive', 'start']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/v1/admin/rides/{$ride}/cancel", ['reason' => 'Nasiraan ang tricycle'])->assertOk()->assertJsonPath('data.cancelled_by', 'admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ride.cancelled_by_admin', 'auditable_id' => $ride]);
        $this->getJson('/api/v1/admin/rides?status=cancelled')->assertJsonCount(1, 'data');
    }

    public function test_ride_07_no_driver_in_time_cancels_the_request_by_the_system(): void
    {
        $this->driver(self::LAT + 0.001);
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);

        $this->travel(121)->seconds();
        Sanctum::actingAs($passenger);
        $this->getJson("/api/v1/rides/{$ride}")->assertOk()   // the passenger's poll notices
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'system')
            ->assertJsonPath('data.cancel_reason', 'no_driver');
        $this->assertSame(0, RideOffer::where('ride_request_id', $ride)->where('status', 'offered')->count());
        $this->assertSame(1, RideOffer::where('ride_request_id', $ride)->where('status', 'expired')->count());

        $this->bookId($passenger); // and can book again ("Subukan ulit")
    }

    public function test_the_expiry_command_and_late_accepts(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger());
        $this->travel(3)->minutes();
        // keep the driver "fresh" after the clock jump, so only the RIDE has run out
        $this->driverOf($driver)->forceFill(['location_updated_at' => now()])->save();

        $this->as($driver, $ride, 'accept')->assertStatus(409)->assertJsonPath('code', 'OFFER_GONE');
        $this->artisan('rides:expire')->assertSuccessful();
        $this->assertSame('cancelled', $this->statusOf($ride));
    }

    public function test_stale_drivers_are_set_offline(): void
    {
        $fresh = $this->driver();
        $stale = $this->driver();
        $this->driverOf($stale)->forceFill(['location_updated_at' => now()->subMinutes(4)])->save();

        $this->artisan('drivers:offline-stale')->assertSuccessful();

        $this->assertTrue($this->driverOf($fresh)->is_online);
        $this->assertFalse($this->driverOf($stale)->is_online);
    }

    public function test_ride_10_nobody_else_can_open_or_touch_the_ride(): void
    {
        [$ride, , $driver] = $this->rideAt(['accept']);

        Sanctum::actingAs($this->passenger());
        $this->getJson("/api/v1/rides/{$ride}")->assertNotFound();
        $this->postJson("/api/v1/rides/{$ride}/cancel")->assertNotFound();

        Sanctum::actingAs($this->driver(self::LAT + 0.002));
        $this->getJson("/api/v1/rides/{$ride}")->assertNotFound();
        $this->getJson('/api/v1/rides')->assertJsonCount(0, 'data');
    }

    public function test_phone_numbers_only_between_the_matched_pair_during_the_ride(): void
    {
        [$ride, $passenger, $driver] = $this->rideAt(['accept', 'depart']);

        Sanctum::actingAs($passenger);
        $this->getJson("/api/v1/rides/{$ride}")
            ->assertJsonPath('data.driver.phone', $driver->phone)
            ->assertJsonPath('data.vehicle.plate_number', $this->driverOf($driver)->activeVehicle->plate_number)
            ->assertJsonPath('data.passenger', null);
        Sanctum::actingAs($driver);
        $this->getJson("/api/v1/rides/{$ride}")->assertJsonPath('data.passenger.phone', $passenger->phone)
            ->assertJsonPath('data.passenger.first_name', $passenger->first_name);

        $this->as($driver, $ride, 'arrive');
        $this->as($driver, $ride, 'start');
        $this->as($driver, $ride, 'complete');

        Sanctum::actingAs($passenger);
        $this->getJson("/api/v1/rides/{$ride}")->assertJsonPath('data.driver.phone', null)->assertJsonPath('data.driver.location', null);
        Sanctum::actingAs($driver);
        $this->getJson("/api/v1/rides/{$ride}")->assertJsonPath('data.passenger.phone', null);
    }

    public function test_the_trail_records_points_and_moves_the_driver_the_passenger_sees(): void
    {
        [$ride, $passenger, $driver] = $this->rideAt(['accept', 'depart']);

        $this->as($driver, $ride, 'locations', ['points' => [
            ['lat' => self::LAT + 0.004, 'lng' => self::LNG, 'accuracy_m' => 12],
            ['lat' => self::LAT + 0.003, 'lng' => self::LNG, 'accuracy_m' => 8],
        ]])->assertCreated()->assertJsonPath('data.saved', 2);

        $this->assertSame(2, RideRequest::findOrFail($ride)->locations()->count());
        Sanctum::actingAs($passenger);
        $location = $this->getJson("/api/v1/rides/{$ride}")->json('data.driver.location');
        $this->assertSame('15.3530000', $location['lat']);                        // the newest point
        $this->assertEqualsWithDelta(0.33, $location['distance_to_pickup_km'], 0.01); // "0.33 km ang layo"

        $this->as($this->driver(self::LAT + 0.002), $ride, 'locations', ['points' => [['lat' => self::LAT, 'lng' => self::LNG]]])->assertNotFound();
    }

    public function test_active_ride_and_history(): void
    {
        [$ride, $passenger, $driver] = $this->rideAt(['accept']);

        Sanctum::actingAs($passenger);
        $this->getJson('/api/v1/rides/active')->assertJsonPath('data.id', $ride);
        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/rides/active')->assertJsonPath('data.id', $ride);

        foreach (['depart', 'arrive', 'start', 'complete'] as $step) {
            $this->as($driver, $ride, $step);
        }
        $this->getJson('/api/v1/rides/active')->assertJsonPath('data', null);
        $this->getJson('/api/v1/rides')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'completed');
    }
}
