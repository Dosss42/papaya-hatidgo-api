<?php

namespace Tests\Feature\Rides;

use App\Enums\RideType;
use App\Models\FareSetting;
use App\Models\RideRequest;
use App\Services\FareService;
use App\Services\GeoService;
use Tests\TestCase;

/**
 * Every fare rule of phase-0 § E.1 (phase-0 § L: "FareService: one-way, two-way, minimum fare,
 * waiting fee = 0"). Fare settings are built in memory: no database needed.
 */
class FareServiceTest extends TestCase
{
    private function fare(array $over = []): FareSetting
    {
        return new FareSetting(array_merge([
            'base_fare' => 30, 'rate_per_km' => 10, 'minimum_fare' => 30, 'return_rate_multiplier' => 1,
            'waiting_free_minutes' => 0, 'waiting_fee_per_minute' => 0, 'service_fee' => 0,
        ], $over));
    }

    private function fares(): FareService
    {
        return app(FareService::class);
    }

    public function test_one_way_is_base_plus_distance(): void
    {
        $q = $this->fares()->quote(RideType::OneWay, 3.2, null, $this->fare());

        $this->assertSame(6200, $q->outbound);        // ₱30 + 3.2 km × ₱10
        $this->assertNull($q->return);
        $this->assertSame(6200, $q->total());
        $this->assertFalse($q->minimumApplied);
        $this->assertSame('62.00', $q->toArray()['total']);
    }

    public function test_the_minimum_fare_applies_to_short_trips(): void
    {
        $q = $this->fares()->quote(RideType::OneWay, 0.5, null, $this->fare(['base_fare' => 10, 'minimum_fare' => 30]));

        $this->assertSame(3000, $q->outbound);        // ₱10 + ₱5 = ₱15 → minimum ₱30
        $this->assertTrue($q->minimumApplied);
    }

    public function test_balikan_adds_the_return_with_its_multiplier_and_no_second_base(): void
    {
        $q = $this->fares()->quote(RideType::TwoWay, 3.2, 30, $this->fare(['return_rate_multiplier' => 0.8]));

        $this->assertSame(6200, $q->outbound);
        $this->assertSame(2560, $q->return);          // 3.2 km × ₱10 × 0.8, no base fare
        $this->assertSame(3.2, $q->returnDistanceKm); // v1: the way back = the way there
        $this->assertSame(0, $q->waiting);            // the seeded rule: waiting is free
        $this->assertSame(8760, $q->total());
    }

    public function test_the_waiting_fee_charges_only_minutes_past_the_free_ones(): void
    {
        $rule = $this->fare(['waiting_free_minutes' => 10, 'waiting_fee_per_minute' => 2]);

        $this->assertSame(4000, $this->fares()->waitingFee(30, $rule)); // (30 − 10) × ₱2
        $this->assertSame(0, $this->fares()->waitingFee(8, $rule));     // within the free minutes
        $this->assertSame(0, $this->fares()->quote(RideType::OneWay, 2, 60, $rule)->waiting); // one-way never waits
    }

    public function test_the_service_fee_is_added_and_centavos_never_drift(): void
    {
        $q = $this->fares()->quote(RideType::OneWay, 2.35, null, $this->fare(['rate_per_km' => 10.15, 'service_fee' => 2.5]));

        // ₱30 + 2.35 × ₱10.15 = ₱53.8525 → ₱53.85 (one rounding, in centavos), + ₱2.50
        $this->assertSame(5385, $q->outbound);
        $this->assertSame(5635, $q->total());
        $this->assertSame('56.35', $q->toArray()['total']);
    }

    public function test_ending_a_balikan_at_the_destination_drops_the_return_fare(): void
    {
        $ride = new RideRequest;
        $ride->forceFill(['ride_type' => RideType::TwoWay, 'outbound_distance_km' => 3.2]);
        $ride->setRelation('fareSetting', $this->fare());

        $q = $this->fares()->finalize($ride, returnLeg: false);

        $this->assertSame(0, $q->return);
        $this->assertSame(6200, $q->total());
    }

    public function test_haversine_distance_is_right(): void
    {
        // One degree of latitude is about 111.19 km everywhere on Earth.
        $this->assertEqualsWithDelta(111.19, GeoService::distanceKm(15.0, 121.0, 16.0, 121.0), 0.01);
        $this->assertSame(0.0, GeoService::distanceKm(15.35, 121.05, 15.35, 121.05));
    }
}
