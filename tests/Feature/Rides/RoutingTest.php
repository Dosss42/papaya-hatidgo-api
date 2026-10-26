<?php

namespace Tests\Feature\Rides;

use App\Models\RideRequest;
use App\Services\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The fare along the ROADS (after Phase 13), against a faked OpenRouteService: nothing leaves the PC.
 * The trip in RideHelpers is 2.22 km in a straight line; here the road is 3.10 km.
 */
class RoutingTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
        $this->app->instance(RoutingService::class, new RoutingService('test-ors-key', 1.3));
        Http::preventStrayRequests();
    }

    private function fakeRoad(int $meters = 3100): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response([
            'features' => [[
                'properties' => ['summary' => ['distance' => $meters, 'duration' => 420]],
                'geometry' => ['coordinates' => [[121.05, 15.35], [121.06, 15.36], [121.05, 15.37]]], // lng, lat
            ]],
        ])]);
    }

    public function test_the_estimate_and_the_booking_use_the_road_distance_and_share_one_route(): void
    {
        $this->fakeRoad();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $estimate = $this->postJson('/api/v1/fare/estimate', $this->trip())->assertOk();
        $this->assertSame('3.10', $estimate->json('data.distance_km'));
        $this->assertTrue($estimate->json('data.by_road'));
        $this->assertSame([[15.35, 121.05], [15.36, 121.06], [15.37, 121.05]], $estimate->json('data.route'));
        $this->assertSame('61.00', $estimate->json('data.options.0.total')); // ₱30 + 3.10 × ₱10

        $ride = $this->book($passenger, ['expected_total' => '61.00'])->assertCreated();
        $this->assertSame('3.10', $ride->json('data.distance_km'));
        $this->assertSame([[15.35, 121.05], [15.36, 121.06], [15.37, 121.05]], $ride->json('data.route'));
        $this->assertTrue(RideRequest::findOrFail($ride->json('data.id'))->distance_by_road);

        // The key goes in a header to OpenRouteService only, with the points as lng, lat.
        Http::assertSentCount(1); // the booking reused the estimate's route (cached)
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'test-ors-key')
            && $r['coordinates'] === [[121.05, 15.35], [121.05, 15.37]]);
    }

    public function test_when_the_routing_service_is_down_the_straight_line_times_the_detour_is_used(): void
    {
        Http::fake(['api.openrouteservice.org/*' => Http::response(['error' => ['message' => 'quota']], 503)]);
        Sanctum::actingAs($this->passenger());

        $estimate = $this->postJson('/api/v1/fare/estimate', $this->trip())->assertOk();

        $this->assertSame('2.89', $estimate->json('data.distance_km')); // 2.22 km × 1.3
        $this->assertFalse($estimate->json('data.by_road'));
        $this->assertCount(2, $estimate->json('data.route')); // just pickup → destination
    }

    public function test_a_long_road_line_is_thinned_for_the_phone(): void
    {
        $points = array_map(fn (int $i) => [15.0 + $i / 10000, 121.0], range(0, 999));

        $thin = RoutingService::thin($points);

        $this->assertLessThanOrEqual(RoutingService::MAX_POINTS, count($thin));
        $this->assertSame($points[0], $thin[0]);
        $this->assertSame($points[999], $thin[count($thin) - 1]); // the end is never cut off
    }
}
