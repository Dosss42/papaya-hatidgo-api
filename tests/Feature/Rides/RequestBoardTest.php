<?php

namespace Tests\Feature\Rides;

use App\Models\RideOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The request board (the student's hybrid dispatch, after Phase 13): drivers within 5 km see open
 * requests and may choose one; the nearest drivers (3 km) still get the ringing alert.
 * 0.01° of latitude ≈ 1.11 km.
 */
class RequestBoardTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    private function board(): array
    {
        return $this->getJson('/api/v1/drivers/me/offers')->assertOk()->json('meta.board');
    }

    public function test_a_driver_4_km_away_sees_the_request_on_the_board_but_gets_no_alert(): void
    {
        $board = $this->driver(self::LAT - 0.036); // ~4 km south: outside the 3 km alert, inside the 5 km board
        $ride = $this->bookId($this->passenger());

        Sanctum::actingAs($board);
        $this->getJson('/api/v1/drivers/me/offers')->assertOk()->assertJsonCount(0, 'data'); // no ringing offer
        $list = $this->board();
        $this->assertCount(1, $list);
        $this->assertSame($ride, $list[0]['ride_id']);
        $this->assertNull($list[0]['offer_id']);
        $this->assertEqualsWithDelta(4.0, $list[0]['pickup']['distance_from_you_km'], 0.1);
        $this->assertArrayNotHasKey('passenger', $list[0]); // nothing personal before accepting
    }

    public function test_choosing_from_the_board_accepts_the_ride_and_only_one_driver_wins(): void
    {
        $first = $this->driver(self::LAT - 0.036);
        $second = $this->driver(self::LAT - 0.040);
        $ride = $this->bookId($this->passenger());

        $this->as($first, $ride, 'accept')->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, RideOffer::where('ride_request_id', $ride)->where('driver_id', $this->driverOf($first)->id)->count());

        $this->as($second, $ride, 'accept')->assertStatus(409)->assertJsonPath('code', 'RIDE_TAKEN');
        Sanctum::actingAs($second);
        $this->assertSame([], $this->board()); // taken: gone from everyone's board
    }

    public function test_requests_beyond_the_board_reach_are_neither_shown_nor_accepted(): void
    {
        $far = $this->driver(self::LAT - 0.06); // ~6.7 km
        $ride = $this->bookId($this->passenger());

        Sanctum::actingAs($far);
        $this->assertSame([], $this->board());
        $this->as($far, $ride, 'accept')->assertNotFound();

        // The admin widens the board: now it shows.
        $this->setting('matching.board_radius_km', '8');
        Sanctum::actingAs($far);
        $this->assertCount(1, $this->board());
    }

    public function test_offline_drivers_and_drivers_on_a_ride_see_no_board(): void
    {
        $offline = $this->driver(self::LAT - 0.036, online: false);
        $busy = $this->driver(self::LAT - 0.001);
        $first = $this->bookId($this->passenger());
        $this->as($busy, $first, 'accept')->assertOk();
        $this->bookId($this->passenger());

        Sanctum::actingAs($offline);
        $this->assertSame([], $this->board());
        Sanctum::actingAs($busy);
        $this->assertSame([], $this->board());
    }
}
