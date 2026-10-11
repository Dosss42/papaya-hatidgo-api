<?php

namespace Tests\Feature\Rides;

use App\Enums\AccountStatus;
use App\Models\RideOffer;
use App\Models\RideRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Ride types, the fare estimate and booking with every gate (Phase 9 steps 9.1–9.2). */
class BookingTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    public function test_ride_types_say_which_are_switched_on(): void
    {
        Sanctum::actingAs($this->passenger());
        $this->setting('ride.two_way_enabled', '0');

        $this->getJson('/api/v1/ride-types')->assertOk()
            ->assertJsonPath('data.0', ['ride_type' => 'one_way', 'enabled' => true])
            ->assertJsonPath('data.1', ['ride_type' => 'two_way', 'enabled' => false]);
    }

    public function test_the_estimate_prices_every_enabled_type_at_once(): void
    {
        Sanctum::actingAs($this->passenger());

        $response = $this->postJson('/api/v1/fare/estimate', $this->trip(['wait_minutes' => 30]))->assertOk();

        $this->assertSame('2.22', $response->json('data.distance_km'));       // 0.02° of latitude
        $this->assertSame(['one_way', 'two_way'], array_column($response->json('data.options'), 'ride_type'));
        $this->assertSame('52.20', $response->json('data.options.0.total')); // ₱30 + 2.22 × ₱10
        $this->assertSame('74.40', $response->json('data.options.1.total')); // + return 22.20, waiting ₱0

        $this->setting('ride.two_way_enabled', '0');
        $this->postJson('/api/v1/fare/estimate', $this->trip())->assertJsonCount(1, 'data.options'); // the card isn't shown at all
    }

    public function test_pins_outside_the_area_or_too_close_are_refused_on_the_right_pin(): void
    {
        Sanctum::actingAs($this->passenger());

        $this->postJson('/api/v1/fare/estimate', $this->trip(['pickup_lat' => self::LAT + 1]))
            ->assertStatus(422)->assertJsonValidationErrors(['pickup_lat'])->assertJsonMissingValidationErrors(['destination_lat']);
        $this->postJson('/api/v1/fare/estimate', $this->trip(['destination_lat' => self::LAT + 0.0005])) // ~55 m
            ->assertStatus(422)->assertJsonValidationErrors(['destination_lat']);
        $this->postJson('/api/v1/fare/estimate', ['pickup_lat' => 200])->assertStatus(422);
    }

    public function test_drivers_cannot_estimate_or_book(): void
    {
        Sanctum::actingAs($this->driver());

        $this->postJson('/api/v1/fare/estimate', $this->trip())->assertForbidden();
        $this->postJson('/api/v1/rides', $this->trip())->assertForbidden();
    }

    public function test_booking_recomputes_the_fare_and_offers_the_ride_to_nearby_drivers(): void
    {
        $near = $this->driver(self::LAT + 0.005);

        $response = $this->book($this->passenger(), ['expected_total' => '52.20'])->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.fare.estimated_total', '52.20')
            ->assertJsonPath('data.driver', null);

        $ride = RideRequest::findOrFail($response->json('data.id'));
        $this->assertSame('HG-'.str_pad((string) $ride->id, 6, '0', STR_PAD_LEFT), $response->json('data.reference_no'));
        $this->assertTrue($ride->expires_at->equalTo($ride->requested_at->copy()->addSeconds(120)));
        $this->assertDatabaseHas('ride_offers', ['ride_request_id' => $ride->id, 'driver_id' => $this->driverOf($near)->id, 'status' => 'offered']);
    }

    public function test_a_fare_sent_by_the_app_is_never_used(): void
    {
        $id = $this->bookId($this->passenger(), ['outbound_fare' => 1, 'estimated_total' => 1, 'status' => 'completed']);

        $ride = RideRequest::findOrFail($id);
        $this->assertSame('52.20', $ride->estimated_total);
        $this->assertSame('requested', $ride->status->value);
    }

    public function test_ride_02_no_subscription_no_booking(): void
    {
        $this->book($this->passenger(subscribed: false))->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
    }

    public function test_ride_03_one_active_ride_at_a_time(): void
    {
        $passenger = $this->passenger();
        $first = $this->bookId($passenger);

        $this->book($passenger)->assertStatus(409)->assertJsonPath('code', 'ACTIVE_RIDE_EXISTS')->assertJsonPath('data.ride_id', $first);
    }

    public function test_ride_04_disabled_types_and_suspended_accounts_are_refused(): void
    {
        $this->setting('ride.two_way_enabled', '0');
        $this->book($this->passenger(), ['ride_type' => 'two_way'])->assertStatus(422)->assertJsonPath('code', 'RIDE_TYPE_DISABLED');

        $suspended = $this->passenger();
        $suspended->forceFill(['account_status' => AccountStatus::Suspended])->save();
        $this->book($suspended)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_ride_09_a_changed_fare_is_refused_with_the_new_total(): void
    {
        $this->book($this->passenger(), ['expected_total' => '45.00'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'FARE_CHANGED')
            ->assertJsonPath('data.estimate.total', '52.20');
        $this->assertDatabaseCount('ride_requests', 0);
    }

    public function test_balikan_keeps_the_stay_and_one_way_refuses_it(): void
    {
        $id = $this->bookId($this->passenger(), ['ride_type' => 'two_way', 'wait_minutes' => 30]);
        $this->assertSame(30, RideRequest::findOrFail($id)->wait_minutes);

        $this->book($this->passenger(), ['wait_minutes' => 30])->assertStatus(422)->assertJsonValidationErrors(['wait_minutes']);
        $this->book($this->passenger(), ['ride_type' => 'two_way', 'wait_minutes' => 45])->assertStatus(422);
    }

    public function test_with_no_driver_around_the_ride_still_waits_for_one(): void
    {
        $id = $this->bookId($this->passenger());

        $this->assertSame(0, RideOffer::where('ride_request_id', $id)->count());
        $this->assertSame('requested', $this->statusOf($id));
    }
}
