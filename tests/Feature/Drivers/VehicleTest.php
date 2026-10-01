<?php

namespace Tests\Feature\Drivers;

use App\Enums\AccountStatus;
use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverComplianceService;
use Database\Seeders\DriverRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** /api/v1/vehicles (Phase 7 step 7.2): register, list and edit a driver's own tricycle. */
class VehicleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DriverRequirementSeeder::class);
    }

    private function actingDriver(): Driver
    {
        $user = User::factory()->driver()->create();
        $driver = Driver::forceCreate(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $driver;
    }

    private function tricycle(array $overrides = []): array
    {
        return array_merge(['plate_number' => 'abc 1234', 'color' => 'Pula', 'body_number' => ' t-12 '], $overrides);
    }

    public function test_a_driver_registers_a_tricycle_and_it_becomes_active(): void
    {
        $driver = $this->actingDriver();

        $this->postJson('/api/v1/vehicles', $this->tricycle())
            ->assertCreated()
            ->assertJsonPath('data.plate_number', 'ABC1234') // one stored form
            ->assertJsonPath('data.body_number', 'T-12')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_active', true);

        $driver->refresh();
        $this->assertNotNull($driver->active_vehicle_id);
        $this->assertSame(ComplianceStatus::PendingVerification, $driver->compliance_status); // papers still missing
    }

    public function test_a_second_tricycle_is_allowed_but_not_made_active(): void
    {
        $this->actingDriver();
        $this->postJson('/api/v1/vehicles', $this->tricycle())->assertCreated();

        $this->postJson('/api/v1/vehicles', $this->tricycle(['plate_number' => 'XYZ 999', 'body_number' => null]))
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_the_same_plate_typed_differently_is_a_duplicate(): void
    {
        $this->actingDriver();
        $this->postJson('/api/v1/vehicles', $this->tricycle())->assertCreated();

        $this->postJson('/api/v1/vehicles', $this->tricycle(['plate_number' => 'ABC-1234', 'body_number' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.plate_number.0', 'May nakarehistro nang tricycle na may plate number na ito.');
    }

    public function test_plate_and_color_are_required_and_the_plate_format_is_checked(): void
    {
        $this->actingDriver();

        $this->postJson('/api/v1/vehicles', ['plate_number' => 'A!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plate_number', 'color']);
    }

    public function test_a_passenger_cannot_use_the_vehicle_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->passenger()->create());

        $this->getJson('/api/v1/vehicles')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_a_driver_lists_only_their_own_tricycles(): void
    {
        $other = Driver::forceCreate(['user_id' => User::factory()->driver()->create()->id]);
        Vehicle::forceCreate(['driver_id' => $other->id, 'plate_number' => 'OTH001', 'color' => 'Asul']);

        $this->actingDriver();
        $this->postJson('/api/v1/vehicles', $this->tricycle())->assertCreated();

        $this->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plate_number', 'ABC1234');
    }

    public function test_another_drivers_tricycle_answers_404_not_403(): void
    {
        $other = Driver::forceCreate(['user_id' => User::factory()->driver()->create()->id]);
        $theirs = Vehicle::forceCreate(['driver_id' => $other->id, 'plate_number' => 'OTH001', 'color' => 'Asul']);

        $this->actingDriver();

        $this->patchJson("/api/v1/vehicles/{$theirs->id}", ['color' => 'Itim'])->assertNotFound();
        $this->assertSame('Asul', $theirs->refresh()->color);
    }

    public function test_changing_only_the_color_keeps_a_verified_tricycle_verified(): void
    {
        [$driver, $vehicle] = $this->verifiedDriver();

        $this->patchJson("/api/v1/vehicles/{$vehicle->id}", ['color' => 'Berde'])
            ->assertOk()
            ->assertJsonPath('data.color', 'Berde')
            ->assertJsonPath('data.status', 'verified');

        $this->assertSame(ComplianceStatus::Verified, $driver->refresh()->compliance_status);
    }

    public function test_keeping_the_same_plate_is_not_a_change(): void
    {
        [, $vehicle] = $this->verifiedDriver();

        $this->patchJson("/api/v1/vehicles/{$vehicle->id}", ['plate_number' => 'abc-1234', 'color' => 'Pula'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');
    }

    public function test_a_new_plate_voids_the_tricycle_papers_with_a_reason(): void
    {
        [$driver, $vehicle] = $this->verifiedDriver();

        $this->patchJson("/api/v1/vehicles/{$vehicle->id}", ['plate_number' => 'NEW 5678'])
            ->assertOk()
            ->assertJsonPath('data.plate_number', 'NEW5678')
            ->assertJsonPath('data.status', 'pending');

        $papers = DriverDocument::where('vehicle_id', $vehicle->id)->with('latestReview')->get();
        $this->assertCount(2, $papers); // OR/CR + MTOP
        foreach ($papers as $paper) {
            $this->assertSame(DocumentStatus::ResubmissionRequired, $paper->status);
            $this->assertSame(ReviewAction::InvalidatedBySystem, $paper->latestReview->action);
            $this->assertNull($paper->latestReview->reviewer_id); // the system acted
            $this->assertSame('Nagbago ang plate number. I-upload ang OR/CR at MTOP na may bagong plate.', $paper->latestReview->reason);
        }

        // The driver's own papers (license, clearance) are untouched; the driver must fix the tricycle papers.
        $this->assertSame(2, DriverDocument::where('driver_id', $driver->id)->whereNull('vehicle_id')
            ->where('status', DocumentStatus::Approved)->count());
        $this->assertSame(ComplianceStatus::Rejected, $driver->refresh()->compliance_status);
    }

    public function test_the_database_still_refuses_a_rejection_without_an_admin_or_a_system_action_without_a_reason(): void
    {
        [$driver] = $this->verifiedDriver();
        $document = DriverDocument::where('driver_id', $driver->id)->firstOrFail();

        // Only the SYSTEM may act without a reviewer (expired / invalidated), never a rejection.
        try {
            \App\Models\DriverRequirementReview::forceCreate([
                'driver_document_id' => $document->id, 'reviewer_id' => null,
                'action' => ReviewAction::Rejected, 'reason' => 'Malabo ang litrato',
            ]);
            $this->fail('MySQL accepted a rejection with no reviewer');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('chk_review_actor', $e->getMessage());
        }

        // And the system must still say WHY it invalidated a document.
        try {
            \App\Models\DriverRequirementReview::forceCreate([
                'driver_document_id' => $document->id, 'reviewer_id' => null,
                'action' => ReviewAction::InvalidatedBySystem, 'reason' => '',
            ]);
            $this->fail('MySQL accepted a system invalidation with no reason');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('chk_review_reason', $e->getMessage());
        }
    }

    public function test_a_suspended_driver_cannot_change_their_tricycle(): void
    {
        $driver = $this->actingDriver();
        $driver->user->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $this->postJson('/api/v1/vehicles', $this->tricycle())->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    /** A driver with an active tricycle and all 4 papers approved (= verified). */
    private function verifiedDriver(): array
    {
        $driver = $this->actingDriver();
        $vehicle = Vehicle::forceCreate(['driver_id' => $driver->id, 'plate_number' => 'ABC1234', 'color' => 'Pula']);
        $driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();

        foreach (DriverRequirement::all() as $requirement) {
            DriverDocument::forceCreate([
                'driver_id' => $driver->id,
                'driver_requirement_id' => $requirement->id,
                'vehicle_id' => $requirement->applies_to->value === 'vehicle' ? $vehicle->id : null,
                'status' => DocumentStatus::Approved,
                'is_current' => true,
                'expires_at' => now()->addYear()->toDateString(),
            ]);
        }
        app(DriverComplianceService::class)->recalculate($driver);
        $this->assertSame(VehicleStatus::Verified, $vehicle->refresh()->status);

        return [$driver->refresh(), $vehicle];
    }
}
