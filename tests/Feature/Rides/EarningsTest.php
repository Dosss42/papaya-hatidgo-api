<?php

namespace Tests\Feature\Rides;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Driver earnings + the offers poll's is_online (Phase 11 step 11.1). */
class EarningsTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
        $this->setting('matching.max_offers', '50');
        // These tests travel to earlier days; the seeded fares take effect "now", so backdate them.
        \App\Models\FareSetting::query()->update(['effective_from' => '2026-01-01 00:00:00']);
    }

    /** One full one-way ride by this driver, completed now (₱52.20 with the seeded fares). */
    private function completeRide($driver): int
    {
        $this->driverOf($driver)->forceFill(['location_updated_at' => now(), 'is_online' => true])->save();
        $ride = $this->bookId($this->passenger());
        foreach (['accept', 'depart', 'arrive', 'start', 'complete'] as $step) {
            $this->as($driver, $ride, $step)->assertOk();
        }

        return $ride;
    }

    public function test_today_week_and_month_on_the_philippine_calendar(): void
    {
        // Forward in time (the driver's subscription starts the day before he is created).
        $at = fn (int $day, int $hour) => $this->travelTo(now()->setTimezone('Asia/Manila')->setDate(2026, 10, $day)->setTime($hour, 0)->utc());
        $at(2, 9);                     // Friday Oct 2: same month, an earlier week
        $driver = $this->driver(self::LAT + 0.001);
        $this->completeRide($driver);
        $at(12, 9);                    // Monday Oct 12: same week
        $this->completeRide($driver);
        $at(14, 10);                   // Wednesday Oct 14 ("today"): two rides
        $this->completeRide($driver);
        $this->completeRide($driver);

        $this->travelTo(now()->setTimezone('Asia/Manila')->setDate(2026, 10, 14)->setTime(18, 0)->utc());
        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/drivers/me/earnings')->assertOk()
            ->assertJsonPath('data.period', 'day')
            ->assertJsonPath('data.from', '2026-10-14')
            ->assertJsonPath('data.total', '104.40')
            ->assertJsonPath('data.rides_count', 2);
        $this->getJson('/api/v1/drivers/me/earnings?period=week')
            ->assertJsonPath('data.from', '2026-10-12')
            ->assertJsonPath('data.to', '2026-10-18')
            ->assertJsonPath('data.rides_count', 3);
        $this->getJson('/api/v1/drivers/me/earnings?period=month')
            ->assertJsonPath('data.total', '208.80')
            ->assertJsonPath('data.rides_count', 4);
        $this->getJson('/api/v1/drivers/me/earnings?period=year')->assertStatus(422);
    }

    public function test_a_ride_finished_after_midnight_in_manila_counts_for_the_new_day(): void
    {
        // 00:30 on Oct 15 in Manila is still Oct 14 in UTC (16:30).
        $this->travelTo(now()->setTimezone('Asia/Manila')->setDate(2026, 10, 15)->setTime(0, 30)->utc());
        $driver = $this->driver(self::LAT + 0.001);
        $this->completeRide($driver);

        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/drivers/me/earnings')->assertJsonPath('data.from', '2026-10-15')->assertJsonPath('data.rides_count', 1);
    }

    public function test_only_my_completed_rides_count_and_only_drivers_may_ask(): void
    {
        $mine = $this->driver(self::LAT + 0.001);
        $other = $this->driver(self::LAT + 0.002);
        $this->completeRide($other);
        $cancelled = $this->bookId($this->passenger());
        $this->as($mine, $cancelled, 'accept');
        $this->as($mine, $cancelled, 'cancel', ['reason' => 'Nasiraan']);

        Sanctum::actingAs($mine);
        $this->getJson('/api/v1/drivers/me/earnings')->assertJsonPath('data.total', '0.00')->assertJsonPath('data.rides_count', 0);

        Sanctum::actingAs($this->passenger());
        $this->getJson('/api/v1/drivers/me/earnings')->assertForbidden();
    }

    public function test_the_offers_poll_says_whether_the_server_still_has_the_driver_online(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        Sanctum::actingAs($driver);
        $this->freezeSecond();
        $this->getJson('/api/v1/drivers/me/offers')
            ->assertJsonPath('meta.is_online', true)
            ->assertJsonPath('meta.server_now', now()->toIso8601ZuluString()); // the app's clock reference

        $this->artisan('drivers:offline-stale'); // nothing stale yet
        $this->travel(4)->minutes();
        $this->artisan('drivers:offline-stale');
        $this->getJson('/api/v1/drivers/me/offers')->assertJsonPath('meta.is_online', false);
    }
}
