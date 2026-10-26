<?php

namespace Tests\Feature\Rides;

use App\Enums\AccountStatus;
use App\Enums\VehicleStatus;
use App\Models\RideOffer;
use App\Models\RideRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Matching (Phase 9 steps 9.2–9.3, phase-0 § E.5): who gets the offer, and the race to accept. */
class MatchingTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    /** @return list<int> the driver ids that received an offer for this ride */
    private function offeredTo(int $ride): array
    {
        return RideOffer::where('ride_request_id', $ride)->orderBy('id')->pluck('driver_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_match_01_only_eligible_drivers_get_the_offer(): void
    {
        $eligible = $this->driver(self::LAT + 0.001);

        $this->driver(self::LAT + 0.001, online: false);                       // offline
        $this->driverOf($this->driver(self::LAT + 0.001))->forceFill(['location_updated_at' => now()->subMinutes(4)])->save(); // stale
        $this->driver(self::LAT + 0.001, verified: false);                     // papers not verified
        $this->driver(self::LAT + 0.001, subscribed: false);                   // no subscription
        $this->driver(self::LAT + 0.05);                                        // ~5.5 km: beyond the 3 km radius
        $suspended = $this->driver(self::LAT + 0.001);
        $suspended->forceFill(['account_status' => AccountStatus::Suspended])->save();
        $rejectedTricycle = $this->driverOf($this->driver(self::LAT + 0.001));
        $rejectedTricycle->activeVehicle->forceFill(['status' => VehicleStatus::Rejected])->save();
        $busy = $this->driver(self::LAT + 0.001);                               // already on a ride
        $other = $this->bookId($this->passenger());
        $this->as($busy, $other, 'accept')->assertOk();

        $ride = $this->bookId($this->passenger());

        $this->assertSame([$this->driverOf($eligible)->id], $this->offeredTo($ride));
    }

    public function test_the_nearest_drivers_first_and_at_most_max_offers(): void
    {
        $this->setting('matching.max_offers', '2');
        $far = $this->driver(self::LAT + 0.02);
        $nearest = $this->driver(self::LAT + 0.001);
        $middle = $this->driver(self::LAT + 0.01);

        $ride = $this->bookId($this->passenger());

        $this->assertSame([$this->driverOf($nearest)->id, $this->driverOf($middle)->id], $this->offeredTo($ride));
        $this->assertNotContains($this->driverOf($far)->id, $this->offeredTo($ride));
    }

    public function test_match_02_two_drivers_accept_the_same_ride_only_one_wins(): void
    {
        $first = $this->driver(self::LAT + 0.001);
        $second = $this->driver(self::LAT + 0.002);
        $ride = $this->bookId($this->passenger());

        $this->as($first, $ride, 'accept')->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->as($second, $ride, 'accept')->assertStatus(409)->assertJsonPath('code', 'RIDE_TAKEN');

        $saved = RideRequest::findOrFail($ride);
        $this->assertSame($this->driverOf($first)->id, (int) $saved->driver_id);
        $this->assertSame((int) $this->driverOf($first)->active_vehicle_id, (int) $saved->vehicle_id);
        $this->assertSame(0, RideOffer::where('ride_request_id', $ride)->where('status', 'offered')->count(), 'every offer closed');
    }

    public function test_match_03_a_decline_offers_the_ride_to_the_next_nearest(): void
    {
        $this->setting('matching.max_offers', '1');
        $nearest = $this->driver(self::LAT + 0.001);
        $next = $this->driver(self::LAT + 0.01);
        $ride = $this->bookId($this->passenger());
        $this->assertSame([$this->driverOf($nearest)->id], $this->offeredTo($ride));

        $this->as($nearest, $ride, 'decline')->assertOk();

        $this->assertDatabaseHas('ride_offers', ['ride_request_id' => $ride, 'driver_id' => $this->driverOf($nearest)->id, 'status' => 'declined']);
        $this->assertSame([$this->driverOf($nearest)->id, $this->driverOf($next)->id], $this->offeredTo($ride));
        $this->as($nearest, $ride, 'accept')->assertStatus(409); // declined: no second chance at it
    }

    public function test_a_driver_who_comes_online_later_gets_it_at_the_next_passenger_poll(): void
    {
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);
        $this->assertSame([], $this->offeredTo($ride));

        $late = $this->driver(self::LAT + 0.001);
        Sanctum::actingAs($passenger);
        $this->getJson("/api/v1/rides/{$ride}")->assertOk();

        $this->assertSame([$this->driverOf($late)->id], $this->offeredTo($ride));
    }

    public function test_accept_rechecks_the_driver_at_that_moment(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger());
        $this->driverOf($driver)->forceFill(['location_updated_at' => now()->subMinutes(5)])->save(); // phone died

        $this->as($driver, $ride, 'accept')->assertForbidden()->assertJsonPath('code', 'CANNOT_ACCEPT');
        $this->assertSame('requested', $this->statusOf($ride));
    }

    public function test_a_ride_beyond_the_request_board_cannot_be_accepted(): void
    {
        // Within 5 km a driver may choose it from the request board (RequestBoardTest); beyond, never.
        $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger());
        $stranger = $this->driver(self::LAT + 0.06); // ~6.7 km: beyond the alert AND the board

        $this->as($stranger, $ride, 'accept')->assertNotFound();
        $this->as($stranger, $ride, 'decline')->assertNotFound();
    }

    public function test_the_offer_shows_what_to_decide_with_and_nothing_personal(): void
    {
        $driver = $this->driver(self::LAT - 0.005); // ~0.56 km from the pickup
        $this->bookId($this->passenger(), ['ride_type' => 'two_way', 'wait_minutes' => 30, 'passenger_note' => 'May dalang bayong']);

        Sanctum::actingAs($driver);
        $offer = $this->getJson('/api/v1/drivers/me/offers')->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        $this->assertSame('two_way', $offer['ride_type']);
        $this->assertSame(30, $offer['wait_minutes']);
        $this->assertSame('Harap ng munisipyo', $offer['pickup']['landmark']);
        $this->assertEqualsWithDelta(0.56, $offer['pickup']['distance_from_you_km'], 0.01);
        $this->assertSame('May dalang bayong', $offer['passenger_note']);
        $this->assertArrayNotHasKey('passenger', $offer);
        $this->assertStringNotContainsString('phone', json_encode($offer));
    }

    public function test_drivers_can_ping_their_location_only_while_online(): void
    {
        $driver = $this->driver(online: false);
        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/drivers/me/location', ['lat' => self::LAT, 'lng' => self::LNG])->assertStatus(409)->assertJsonPath('code', 'NOT_ONLINE');

        $this->driverOf($driver)->forceFill(['is_online' => true])->save();
        $this->postJson('/api/v1/drivers/me/location', ['lat' => self::LAT + 0.001, 'lng' => self::LNG])->assertOk();
        $this->assertSame('15.3510000', $this->driverOf($driver)->current_lat);
    }
}
