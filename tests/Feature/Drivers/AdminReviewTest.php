<?php

namespace Tests\Feature\Drivers;

use App\Enums\AccountStatus;
use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\VehicleStatus;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\BusinessDate;
use Database\Seeders\DriverRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Admin review (Phase 7 step 7.4): the driver uploads through the API, the admin decides through the API. */
class AdminReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $driverUser;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DriverRequirementSeeder::class);
        Storage::fake('local');

        $this->admin = User::factory()->admin()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $this->driverUser = User::factory()->driver()->create();
        $this->driver = Driver::forceCreate(['user_id' => $this->driverUser->id]);
        $vehicle = Vehicle::forceCreate(['driver_id' => $this->driver->id, 'plate_number' => 'ABC1234', 'color' => 'Pula']);
        $this->driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();
    }

    // ---------- helpers ----------

    /** The driver uploads one requirement through the real endpoint; returns the document id. */
    private function driverUploads(string $code, ?string $expires = '+1 year'): int
    {
        Sanctum::actingAs($this->driverUser);
        $requirement = DriverRequirement::where('code', $code)->firstOrFail();
        $files = $requirement->max_files === 2
            ? [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')]
            : [UploadedFile::fake()->image("{$code}.jpg")];

        return $this->withHeader('Accept', 'application/json')->post('/api/v1/drivers/me/documents', [
            'requirement_id' => $requirement->id,
            'files' => $files,
            'sides' => ['front', 'back'],
            'expires_at' => $expires ? BusinessDate::today()->modify($expires)->toDateString() /* Philippine calendar, like the API */ : null,
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, int> code => document id */
    private function driverUploadsAll(): array
    {
        $ids = [];
        foreach (['drivers_license', 'or_cr', 'mtop_permit', 'clearance'] as $code) {
            $ids[$code] = $this->driverUploads($code);
        }

        return $ids;
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin);

        return $this;
    }

    // ---------- access ----------

    public function test_drivers_and_passengers_cannot_use_admin_endpoints(): void
    {
        $id = $this->driverUploads('clearance');

        Sanctum::actingAs($this->driverUser);
        $this->getJson('/api/v1/admin/driver-verifications')->assertForbidden();
        $this->postJson("/api/v1/admin/driver-documents/{$id}/approve")->assertForbidden();

        Sanctum::actingAs(User::factory()->passenger()->create());
        $this->getJson("/api/v1/admin/driver-documents/{$id}")->assertForbidden();
    }

    // ---------- the queue ----------

    public function test_the_queue_lists_drivers_with_pending_documents_longest_waiting_first(): void
    {
        $this->driverUploads('clearance');
        $this->travel(1)->minutes();
        $laterUser = User::factory()->driver()->create();
        Driver::forceCreate(['user_id' => $laterUser->id]);
        Sanctum::actingAs($laterUser);
        $this->withHeader('Accept', 'application/json')->post('/api/v1/drivers/me/documents', [
            'requirement_id' => DriverRequirement::where('code', 'clearance')->value('id'),
            'files' => [UploadedFile::fake()->image('c.jpg')],
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertCreated();
        Driver::forceCreate(['user_id' => User::factory()->driver()->create()->id]); // nothing uploaded

        $this->asAdmin()->getJson('/api/v1/admin/driver-verifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.driver_id', $this->driver->id)
            ->assertJsonPath('data.0.plate_number', 'ABC1234')
            ->assertJsonPath('data.0.documents_to_review.0.requirement', 'Clearance')
            ->assertJsonPath('data.0.documents_to_review.0.is_renewal', false)
            ->assertJsonPath('meta.total', 2);
    }

    // ---------- looking at a document ----------

    public function test_the_admin_sees_the_document_with_the_tricycle_and_file_links_but_no_paths(): void
    {
        $id = $this->driverUploads('or_cr');

        $response = $this->asAdmin()->getJson("/api/v1/admin/driver-documents/{$id}")
            ->assertOk()
            ->assertJsonPath('data.vehicle.plate_number', 'ABC1234')
            ->assertJsonPath('data.driver.compliance_status', 'pending_verification')
            ->assertJsonPath('data.files.0.mime_type', 'image/jpeg');

        $this->assertStringStartsWith("/api/v1/admin/driver-documents/{$id}/files/", $response->json('data.files.0.url'));
        $this->assertStringNotContainsString('driver-documents/'.$this->driver->id.'/', $response->getContent()); // no disk path
    }

    public function test_the_file_is_streamed_only_to_an_admin_and_the_viewing_is_logged(): void
    {
        $id = $this->driverUploads('clearance');
        $file = DriverDocument::with('files')->find($id)->files->first();

        $response = $this->asAdmin()->get("/api/v1/admin/driver-documents/{$id}/files/{$file->id}");

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('local')->get($file->file_path), $response->streamedContent());
        $this->assertSame(1, AuditLog::where('action', 'driver_document.file_viewed')->where('actor_id', $this->admin->id)->count());

        // A file id that belongs to ANOTHER document can't be fetched through this one.
        $other = $this->driverUploads('mtop_permit');
        $this->asAdmin()->get("/api/v1/admin/driver-documents/{$other}/files/{$file->id}")->assertNotFound();
    }

    // ---------- decisions ----------

    public function test_approving_all_four_verifies_the_driver_and_the_tricycle_and_is_logged(): void
    {
        foreach ($this->driverUploadsAll() as $id) {
            $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve")
                ->assertOk()
                ->assertJsonPath('data.status', 'approved')
                ->assertJsonPath('data.reviews.0.reviewer', 'Ana Reyes');
        }

        $this->assertSame(ComplianceStatus::Verified, $this->driver->refresh()->compliance_status);
        $this->assertSame(VehicleStatus::Verified, $this->driver->activeVehicle->status);
        $this->assertSame(4, AuditLog::where('action', 'driver_document.approved')->count());
        $this->assertSame('driver_document', AuditLog::first()->auditable_type); // morph map name
    }

    public function test_a_rejection_needs_a_reason_and_the_driver_sees_it(): void
    {
        $id = $this->driverUploads('clearance');

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/reject", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'Ilagay ang dahilan. Babasahin ito ng driver.');

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/reject", ['reason' => 'Malabo ang litrato'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        Sanctum::actingAs($this->driverUser);
        $rows = collect($this->getJson('/api/v1/drivers/me/requirements')
            ->assertJsonPath('compliance_status', 'rejected')
            ->json('requirements'))->keyBy('requirement.code');
        $this->assertSame('rejected', $rows['clearance']['status']);
        $this->assertSame('Malabo ang litrato', $rows['clearance']['document']['reason']);
    }

    public function test_request_resubmission_marks_the_document_and_keeps_the_reason(): void
    {
        $id = $this->driverUploads('drivers_license');

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/request-resubmission", ['reason' => 'Kulang ang likod'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resubmission_required')
            ->assertJsonPath('data.reviews.0.reason', 'Kulang ang likod');
    }

    public function test_a_decided_document_cannot_be_decided_again(): void
    {
        $id = $this->driverUploads('clearance');
        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve")->assertOk();

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/reject", ['reason' => 'Nagbago ang isip'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DOCUMENT_NOT_PENDING');
    }

    public function test_the_admin_can_correct_the_expiry_date_when_approving(): void
    {
        $id = $this->driverUploads('clearance', '+1 year');
        $corrected = now()->addMonths(6)->toDateString();

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve", ['expires_at' => $corrected])
            ->assertOk()
            ->assertJsonPath('data.expires_at', $corrected);

        $log = AuditLog::where('action', 'driver_document.approved')->firstOrFail();
        $this->assertSame($corrected, $log->new_values['expires_at']);
        $this->assertNotSame($corrected, $log->old_values['expires_at']);
    }

    public function test_a_document_that_expired_while_waiting_cannot_be_approved(): void
    {
        $id = $this->driverUploads('clearance', '+1 day');
        $this->travel(3)->days();

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DOCUMENT_EXPIRED');
        $this->assertSame(DocumentStatus::Pending, DriverDocument::find($id)->status);
    }

    public function test_approving_a_renewal_makes_it_current_and_the_driver_stays_verified(): void
    {
        $first = $this->driverUploadsAll();
        foreach ($first as $id) {
            $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve")->assertOk();
        }

        $renewal = $this->driverUploads('drivers_license', '+3 years');
        $this->assertFalse(DriverDocument::find($renewal)->is_current);

        $this->asAdmin()->getJson('/api/v1/admin/driver-verifications')
            ->assertJsonPath('data.0.documents_to_review.0.is_renewal', true);

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$renewal}/approve")
            ->assertOk()
            ->assertJsonPath('data.is_current', true);

        $old = DriverDocument::find($first['drivers_license']);
        $this->assertFalse($old->is_current);
        $this->assertSame(DocumentStatus::Approved, $old->status); // history kept as it was
        $this->assertSame(ComplianceStatus::Verified, $this->driver->refresh()->compliance_status);
    }

    public function test_rejecting_a_renewal_keeps_the_approved_one_and_the_driver_verified(): void
    {
        foreach ($this->driverUploadsAll() as $id) {
            $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve")->assertOk();
        }
        $renewal = $this->driverUploads('clearance', '+2 years');

        $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$renewal}/reject", ['reason' => 'Malabo'])->assertOk();

        $this->assertSame(ComplianceStatus::Verified, $this->driver->refresh()->compliance_status);
    }

    // ---------- suspension ----------

    public function test_suspend_and_reactivate_a_driver(): void
    {
        foreach ($this->driverUploadsAll() as $id) {
            $this->asAdmin()->postJson("/api/v1/admin/driver-documents/{$id}/approve")->assertOk();
        }
        $this->driver->refresh()->forceFill(['is_online' => true])->save();

        $this->asAdmin()->postJson("/api/v1/admin/drivers/{$this->driver->id}/suspend", ['reason' => ''])->assertUnprocessable();
        $this->asAdmin()->postJson("/api/v1/admin/drivers/{$this->driver->id}/suspend", ['reason' => 'Reklamo ng pasahero'])
            ->assertOk()
            ->assertJsonPath('data.account_status', 'suspended')
            ->assertJsonPath('data.is_online', false);

        // The suspended driver can't change anything (and can't log in again: Phase 5).
        Sanctum::actingAs($this->driverUser->refresh());
        $this->withHeader('Accept', 'application/json')->post('/api/v1/drivers/me/documents', [
            'requirement_id' => DriverRequirement::where('code', 'clearance')->value('id'),
            'files' => [UploadedFile::fake()->image('c.jpg')],
            'expires_at' => now()->addYear()->toDateString(),
        ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $this->asAdmin()->postJson("/api/v1/admin/drivers/{$this->driver->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'active')
            ->assertJsonPath('data.compliance_status', 'verified');

        $this->assertSame(AccountStatus::Active, $this->driverUser->refresh()->account_status);
        $suspension = AuditLog::where('action', 'driver.suspended')->firstOrFail();
        $this->assertSame('Reklamo ng pasahero', $suspension->new_values['reason']);
        $this->assertSame(1, AuditLog::where('action', 'driver.reactivated')->count());
    }
}
