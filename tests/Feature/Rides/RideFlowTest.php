<?php

namespace Tests\Feature\Rides;

use App\Models\FareSetting;
use App\Models\RideRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The state machine (Phase 9 step 9.3, phase-0 § E.3): every allowed step, and 409 for the rest. */
class RideFlowTest extends TestCase
{
    use RefreshDatabase;
    use RideHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRideWorld();
    }

    public function test_ride_01_one_way_happy_path_then_rating(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);

        foreach (['accept' => 'accepted', 'depart' => 'driver_arriving', 'arrive' => 'arrived', 'start' => 'in_progress'] as $action => $status) {
            $this->as($driver, $ride, $action)->assertOk()->assertJsonPath('data.status', $status)->assertJsonPath('data.current_leg', null);
        }
        $this->as($driver, $ride, 'complete')->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.fare.total', '52.20')       // final = estimate
            ->assertJsonPath('data.fare.is_final', true);

        $saved = RideRequest::findOrFail($ride);
        foreach (['accepted_at', 'arriving_at', 'arrived_at', 'started_at', 'completed_at'] as $time) {
            $this->assertNotNull($saved->{$time}, "{$time} recorded");
        }
        $this->assertSame('52.20', $saved->final_total);

        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/rating", ['score' => 5, 'comment' => 'Salamat po!'])->assertCreated();
        $this->postJson("/api/v1/rides/{$ride}/rating", ['score' => 1])->assertStatus(409)->assertJsonPath('code', 'ALREADY_RATED');
        $this->getJson("/api/v1/rides/{$ride}")->assertJsonPath('data.rating.score', 5)->assertJsonPath('data.driver.rating.average', 5);
    }

    public function test_ride_05_every_wrong_order_is_409_and_changes_nothing(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger());
        $this->as($driver, $ride, 'accept')->assertOk();

        // accepted: only depart (or cancel) is allowed
        foreach (['arrive', 'start', 'complete', 'reach-destination', 'start-return'] as $wrong) {
            $this->as($driver, $ride, $wrong)->assertStatus(409)->assertJsonPath('code', 'INVALID_TRANSITION')->assertJsonPath('data.status', 'accepted');
        }
        $this->as($driver, $ride, 'depart');
        $this->as($driver, $ride, 'arrive');

        // The phase-0 § L example: complete while arrived
        $response = $this->as($driver, $ride, 'complete')->assertStatus(409);
        $this->assertStringContainsString('nasa sundo na', $response->json('message')); // names the current status
        $this->assertSame('arrived', $this->statusOf($ride));

        $this->as($driver, $ride, 'depart')->assertStatus(409);           // no going back
        $this->as($driver, $ride, 'start');
        $this->as($driver, $ride, 'reach-destination')->assertStatus(409); // one-way has no legs
        $this->as($driver, $ride, 'accept')->assertStatus(409);
        $this->assertSame('in_progress', $this->statusOf($ride));
    }

    public function test_only_the_assigned_driver_moves_the_ride(): void
    {
        $assigned = $this->driver(self::LAT + 0.001);
        $other = $this->driver(self::LAT + 0.002);
        $ride = $this->bookId($this->passenger());
        $this->as($assigned, $ride, 'accept');

        $this->as($other, $ride, 'depart')->assertNotFound();
        Sanctum::actingAs($this->passenger());
        $this->postJson("/api/v1/rides/{$ride}/depart")->assertForbidden(); // passengers have no driver actions
        $this->assertSame('accepted', $this->statusOf($ride));
    }

    public function test_ride_08_balikan_legs_waiting_time_and_total(): void
    {
        // A waiting-fee rule, to see it counted (the seeded rule is ₱0): 10 free minutes, then ₱2/min.
        FareSetting::query()->update(['waiting_free_minutes' => 10, 'waiting_fee_per_minute' => 2]);
        $driver = $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger(), ['ride_type' => 'two_way', 'wait_minutes' => 30]);

        foreach (['accept', 'depart', 'arrive'] as $step) {
            $this->as($driver, $ride, $step)->assertOk();
        }
        $this->as($driver, $ride, 'start')->assertJsonPath('data.current_leg', 'outbound');
        $this->as($driver, $ride, 'complete')->assertStatus(409); // not before the way back

        $this->as($driver, $ride, 'reach-destination')->assertJsonPath('data.current_leg', 'waiting');
        $this->travel(25)->minutes();
        $this->as($driver, $ride, 'start-return')->assertJsonPath('data.current_leg', 'return')->assertJsonPath('data.waiting_minutes', 25);
        $this->as($driver, $ride, 'complete')->assertOk()
            ->assertJsonPath('data.current_leg', null)
            ->assertJsonPath('data.fare.outbound_fare', '52.20')
            ->assertJsonPath('data.fare.return_fare', '22.20')
            ->assertJsonPath('data.fare.waiting_fee', '30.00')        // (25 − 10) × ₱2
            ->assertJsonPath('data.fare.total', '104.40');           // outbound + return + waiting
    }

    public function test_a_balikan_passenger_who_never_returns_can_be_ended_at_the_destination(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $ride = $this->bookId($this->passenger(), ['ride_type' => 'two_way', 'wait_minutes' => 15]);
        foreach (['accept', 'depart', 'arrive', 'start'] as $step) {
            $this->as($driver, $ride, $step);
        }
        $this->as($driver, $ride, 'end-at-destination', ['reason' => 'Hindi bumalik'])->assertStatus(409); // still on the way there

        $this->as($driver, $ride, 'reach-destination');
        $this->as($driver, $ride, 'end-at-destination')->assertStatus(422); // reason required
        $this->as($driver, $ride, 'end-at-destination', ['reason' => 'Hindi na bumalik ang pasahero pagkalipas ng 1 oras'])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.fare.return_fare', '0.00')
            ->assertJsonPath('data.fare.total', '52.20');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ride.ended_at_destination', 'auditable_id' => $ride]);
    }

    public function test_pabalik_na_ako_reaches_the_driver_without_changing_the_status(): void
    {
        $driver = $this->driver(self::LAT + 0.001);
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger, ['ride_type' => 'two_way', 'wait_minutes' => 30]);
        foreach (['accept', 'depart', 'arrive', 'start'] as $step) {
            $this->as($driver, $ride, $step);
        }

        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/passenger-returning")->assertStatus(409); // only while the driver waits

        $this->as($driver, $ride, 'reach-destination');
        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/passenger-returning")->assertOk()->assertJsonPath('data.current_leg', 'waiting');
        $this->postJson("/api/v1/rides/{$ride}/passenger-returning")->assertStatus(429)->assertJsonPath('code', 'RETURNING_TOO_SOON');

        Sanctum::actingAs($driver);
        $this->assertNotNull($this->getJson("/api/v1/rides/{$ride}")->json('data.passenger_returning_at')); // the driver's poll shows it

        $this->travel(6)->minutes();
        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/passenger-returning")->assertOk(); // allowed again after 5 minutes
    }

    public function test_rating_only_a_completed_ride_and_only_your_own(): void
    {
        $passenger = $this->passenger();
        $ride = $this->bookId($passenger);

        Sanctum::actingAs($passenger);
        $this->postJson("/api/v1/rides/{$ride}/rating", ['score' => 4])->assertStatus(409)->assertJsonPath('code', 'RIDE_NOT_COMPLETED');
        $this->postJson("/api/v1/rides/{$ride}/rating", ['score' => 6])->assertStatus(422);

        Sanctum::actingAs($this->passenger());
        $this->postJson("/api/v1/rides/{$ride}/rating", ['score' => 4])->assertNotFound();
    }
}
