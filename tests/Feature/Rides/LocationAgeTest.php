<?php

namespace Tests\Feature\Rides;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The passenger's view of the driver's position says how old it is (Phase 12, server clock). */
class LocationAgeTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    public function test_the_age_of_the_driver_position_counts_on_the_server_clock(): void
    {
        $this->freezeSecond();
        $driver = $this->driver(self::LAT + 0.001);
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);
        $this->as($driver, $ride, 'accept')->assertOk();
        $this->as($driver, $ride, 'locations', ['points' => [['lat' => self::LAT + 0.002, 'lng' => self::LNG]]])->assertCreated();

        Sanctum::actingAs($passenger);
        $this->getJson("/api/v1/rides/{$ride}")->assertJsonPath('data.driver.location.age_seconds', 0);

        $this->travel(70)->seconds(); // no sends for 70 s: the app shows "Last location: 1 min ago"
        $this->getJson("/api/v1/rides/{$ride}")->assertJsonPath('data.driver.location.age_seconds', 70);
    }
}
