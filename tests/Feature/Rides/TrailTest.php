<?php

namespace Tests\Feature\Rides;

use App\Http\Controllers\Api\V1\RideController;
use App\Models\RideLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /rides/{id}/trail: the driven path for the ride's own people (Phase 12 step 12.1). */
class TrailTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    /** A completed ride with `$count` trail points sent by its driver, in batches of 50. */
    private function drivenRide(int $count): array
    {
        $driver = $this->driver(self::LAT + 0.001);
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);
        foreach (['accept', 'depart', 'arrive'] as $step) {
            $this->as($driver, $ride, $step)->assertOk();
        }
        // On the way to the pickup: part of the driver's history, not of the passenger's trip.
        $this->as($driver, $ride, 'locations', ['points' => [['lat' => self::LAT - 0.01, 'lng' => self::LNG, 'recorded_at' => now()->toIso8601ZuluString()]]])->assertCreated();
        $this->travel(1)->seconds();
        $this->as($driver, $ride, 'start')->assertOk();
        $this->travel(15)->minutes(); // the trip's points are all recorded after the start
        $points = collect(range(0, $count - 1))->map(fn (int $i) => [
            'lat' => self::LAT + $i * 0.0001,
            'lng' => self::LNG,
            'recorded_at' => now()->subSeconds($count - $i)->toIso8601ZuluString(),
        ]);
        foreach ($points->chunk(50) as $batch) {
            $this->as($driver, $ride, 'locations', ['points' => $batch->values()->all()])->assertCreated();
        }
        $this->as($driver, $ride, 'complete')->assertOk();

        return [$ride, $driver, $passenger];
    }

    public function test_the_passenger_and_the_driver_see_the_path_oldest_first(): void
    {
        [$ride, $driver, $passenger] = $this->drivenRide(5);

        foreach ([$passenger, $driver] as $who) {
            Sanctum::actingAs($who);
            $res = $this->getJson("/api/v1/rides/{$ride}/trail")->assertOk()
                ->assertJsonPath('data.total_points', 5)
                ->assertJsonCount(5, 'data.points');
            $lats = array_column($res->json('data.points'), 'lat');
            $this->assertSame($lats, collect($lats)->sort()->values()->all()); // driven northwards = oldest first
            $this->assertEqualsWithDelta(self::LAT, $lats[0], 0.0000001);
        }
    }

    public function test_a_long_ride_is_thinned_but_keeps_its_first_and_last_point(): void
    {
        [$ride, , $passenger] = $this->drivenRide(700);

        Sanctum::actingAs($passenger);
        $res = $this->getJson("/api/v1/rides/{$ride}/trail")->assertOk()
            ->assertJsonPath('data.total_points', 700)
            ->assertJsonCount(RideController::TRAIL_MAX_POINTS, 'data.points');
        $points = $res->json('data.points');
        $this->assertEqualsWithDelta(self::LAT, $points[0]['lat'], 0.0000001);
        $this->assertEqualsWithDelta(self::LAT + 699 * 0.0001, end($points)['lat'], 0.0000001);
        $this->assertSame(701, RideLocation::query()->where('ride_request_id', $ride)->count()); // nothing deleted (+1 before the trip)
    }

    public function test_the_way_to_the_pickup_is_not_part_of_the_trip(): void
    {
        [$ride, , $passenger] = $this->drivenRide(3);

        Sanctum::actingAs($passenger);
        $lats = array_column($this->getJson("/api/v1/rides/{$ride}/trail")->json('data.points'), 'lat');
        $this->assertCount(3, $lats);
        $this->assertNotContains(round(self::LAT - 0.01, 7), array_map(fn ($l) => round($l, 7), $lats));
    }

    public function test_nobody_else_can_read_it(): void
    {
        [$ride] = $this->drivenRide(3);

        Sanctum::actingAs($this->passenger());
        $this->getJson("/api/v1/rides/{$ride}/trail")->assertNotFound(); // ids can't be probed
        Sanctum::actingAs($this->driver(self::LAT + 0.002));
        $this->getJson("/api/v1/rides/{$ride}/trail")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/rides/{$ride}/trail")->assertUnauthorized();
    }

    public function test_a_ride_without_points_answers_an_empty_path(): void
    {
        $passenger = $this->passenger();
        $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($passenger);

        Sanctum::actingAs($passenger);
        $this->getJson("/api/v1/rides/{$ride}/trail")->assertOk()
            ->assertJsonPath('data.total_points', 0)
            ->assertJsonPath('data.points', []);
    }
}
