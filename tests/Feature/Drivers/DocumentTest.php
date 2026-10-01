<?php

namespace Tests\Feature\Drivers;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\DriverRequirementReview;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverComplianceService;
use App\Support\BusinessDate;
use Database\Seeders\DriverRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Requirements + document uploads (Phase 7 step 7.3). Files go to a FAKE private disk. */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DriverRequirementSeeder::class);
        Storage::fake('local');

        $user = User::factory()->driver()->create();
        $this->driver = Driver::forceCreate(['user_id' => $user->id]);
        Sanctum::actingAs($user);
    }

    // ---------- helpers ----------

    private function requirement(string $code): DriverRequirement
    {
        return DriverRequirement::where('code', $code)->firstOrFail();
    }

    private function upload(string $code, array $files, array $extra = []): TestResponse
    {
        return $this->withHeader('Accept', 'application/json')->post('/api/v1/drivers/me/documents', array_merge([
            'requirement_id' => $this->requirement($code)->id,
            'files' => $files,
            'expires_at' => now()->addYear()->toDateString(),
        ], $extra));
    }

    private function uploadLicense(array $extra = []): TestResponse
    {
        return $this->upload('drivers_license',
            [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')],
            ['sides' => ['front', 'back']] + $extra);
    }

    private function addTricycle(): Vehicle
    {
        $vehicle = Vehicle::forceCreate(['driver_id' => $this->driver->id, 'plate_number' => 'ABC1234', 'color' => 'Pula']);
        $this->driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();

        return $vehicle;
    }

    // ---------- requirement lists ----------

    public function test_the_four_requirements_are_listed_in_the_requests_language(): void
    {
        $this->getJson('/api/v1/driver-requirements')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.1.name', 'OR/CR ng tricycle')
            ->assertJsonPath('data.0.max_files', 2);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/driver-requirements')
            ->assertJsonPath('data.1.name', "Tricycle's OR/CR");
    }

    public function test_a_new_driver_sees_everything_missing_and_tricycle_papers_locked(): void
    {
        $response = $this->getJson('/api/v1/drivers/me/requirements')
            ->assertOk()
            ->assertJsonPath('compliance_status', 'pending_verification');

        $rows = collect($response->json('requirements'))->keyBy('requirement.code');
        $this->assertSame('missing', $rows['drivers_license']['status']);
        $this->assertFalse($rows['drivers_license']['locked']);
        $this->assertTrue($rows['or_cr']['locked']);
        $this->assertTrue($rows['mtop_permit']['locked']);
    }

    // ---------- uploading ----------

    public function test_a_license_front_and_back_is_stored_privately(): void
    {
        $response = $this->uploadLicense(['document_number' => 'N01-23-456789'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.files.0.side', 'front')
            ->assertJsonPath('data.files.1.side', 'back');

        // No path, no URL in what the phone receives.
        $this->assertStringNotContainsString('driver-documents', $response->getContent());
        $this->assertStringNotContainsString('file_path', $response->getContent());

        $document = DriverDocument::with('files')->findOrFail($response->json('data.id'));
        $this->assertCount(2, $document->files);
        foreach ($document->files as $file) {
            Storage::disk('local')->assertExists($file->file_path);
            $this->assertStringStartsWith("driver-documents/{$this->driver->id}/", $file->file_path);
            $this->assertSame('image/jpeg', $file->mime_type);
        }
    }

    public function test_the_license_needs_exactly_one_front_and_one_back(): void
    {
        $this->upload('drivers_license', [UploadedFile::fake()->image('front.jpg')], ['sides' => ['front']])
            ->assertUnprocessable()->assertJsonPath('code', 'FILES_INVALID');

        $this->upload('drivers_license', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')], ['sides' => ['front', 'front']])
            ->assertUnprocessable()->assertJsonPath('errors.files.0', 'Kailangan ang harap at likod.');

        $this->assertSame(0, DriverDocument::count());
        $this->assertSame([], Storage::disk('local')->allFiles()); // nothing left behind
    }

    public function test_a_program_renamed_to_jpg_is_refused_by_its_content(): void
    {
        // A REAL temporary file (fake() files report their type from the NAME, which proves nothing):
        // Windows program bytes ("MZ" header) inside a file named like a photo.
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 64).'This program cannot be run in DOS mode.');
        $disguised = new UploadedFile($path, 'clearance.jpg', 'image/jpeg', null, true);

        $response = $this->upload('clearance', [$disguised])->assertUnprocessable();
        $this->assertSame('JPG, PNG, o PDF lang ang tinatanggap.', $response->json('errors')['files.0'][0]);
        $this->assertSame(0, DriverDocument::count());
    }

    public function test_a_pdf_is_accepted_and_a_file_over_5_mb_is_not(): void
    {
        $this->upload('clearance', [UploadedFile::fake()->create('clearance.pdf', 300, 'application/pdf')])->assertCreated();

        $response = $this->upload('clearance', [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])
            ->assertUnprocessable();
        // (The error key is "files.0": read it as a plain key, since a dot in a JSON path means nesting.)
        $this->assertSame('Hanggang 5 MB lang bawat file.', $response->json('errors')['files.0'][0]);
    }

    public function test_the_expiry_date_is_required_and_cannot_be_past(): void
    {
        $this->upload('clearance', [UploadedFile::fake()->image('c.jpg')], ['expires_at' => null])
            ->assertUnprocessable()->assertJsonPath('errors.expires_at.0', 'Ilagay ang expiry date.');

        $this->upload('clearance', [UploadedFile::fake()->image('c.jpg')], ['expires_at' => BusinessDate::today()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonPath('errors.expires_at.0', 'Dapat hindi pa lumilipas ang expiry date.');
    }

    public function test_tricycle_papers_need_a_tricycle_first(): void
    {
        $this->upload('or_cr', [UploadedFile::fake()->image('orcr.jpg')])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'TRICYCLE_REQUIRED')
            ->assertJsonPath('message', 'Idagdag muna ang tricycle mo.');

        $vehicle = $this->addTricycle();
        $this->upload('or_cr', [UploadedFile::fake()->image('orcr.jpg')])
            ->assertCreated()
            ->assertJsonPath('data.vehicle_id', $vehicle->id);
    }

    public function test_an_unreviewed_upload_is_replaced_and_its_files_deleted(): void
    {
        $first = $this->upload('clearance', [UploadedFile::fake()->image('blurry.jpg')])->assertCreated();
        $oldPath = DriverDocument::with('files')->find($first->json('data.id'))->files->first()->file_path;

        $this->upload('clearance', [UploadedFile::fake()->image('clear.jpg')])->assertCreated();

        $this->assertSame(1, DriverDocument::count());
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_after_a_rejection_the_new_upload_becomes_current_and_the_history_stays(): void
    {
        $rejected = DriverDocument::find($this->upload('clearance', [UploadedFile::fake()->image('c.jpg')])->json('data.id'));
        $rejected->forceFill(['status' => DocumentStatus::Rejected])->save();
        DriverRequirementReview::forceCreate([
            'driver_document_id' => $rejected->id,
            'reviewer_id' => User::factory()->admin()->create()->id,
            'action' => ReviewAction::Rejected,
            'reason' => 'Malabo ang litrato',
        ]);

        $new = $this->upload('clearance', [UploadedFile::fake()->image('c2.jpg')])->assertCreated();

        $this->assertTrue($new->json('data.is_current'));
        $this->assertFalse($rejected->refresh()->is_current);
        $this->assertSame(DocumentStatus::Rejected, $rejected->status); // history kept
        $this->assertSame(2, DriverDocument::count());
    }

    public function test_renewing_an_approved_document_keeps_the_driver_verified(): void
    {
        $vehicle = $this->addTricycle();
        foreach (DriverRequirement::all() as $requirement) {
            DriverDocument::forceCreate([
                'driver_id' => $this->driver->id,
                'driver_requirement_id' => $requirement->id,
                'vehicle_id' => $requirement->applies_to->value === 'vehicle' ? $vehicle->id : null,
                'status' => DocumentStatus::Approved,
                'is_current' => true,
                'expires_at' => now()->addMonth()->toDateString(),
            ]);
        }
        app(DriverComplianceService::class)->recalculate($this->driver);

        $renewal = $this->uploadLicense(['expires_at' => now()->addYears(3)->toDateString()])->assertCreated();

        $this->assertFalse($renewal->json('data.is_current'));
        $response = $this->getJson('/api/v1/drivers/me/requirements')->assertJsonPath('compliance_status', 'verified');
        $license = collect($response->json('requirements'))->firstWhere('requirement.code', 'drivers_license');
        $this->assertSame('approved', $license['status']);
        $this->assertSame($renewal->json('data.id'), $license['renewal']['id']);
        $this->assertGreaterThan(25, $license['document']['days_until_expiry']);
    }

    public function test_all_four_submitted_is_under_review(): void
    {
        $this->addTricycle();
        $this->uploadLicense()->assertCreated();
        $this->upload('or_cr', [UploadedFile::fake()->image('orcr.jpg')])->assertCreated();
        $this->upload('mtop_permit', [UploadedFile::fake()->image('mtop.jpg')])->assertCreated();
        $this->upload('clearance', [UploadedFile::fake()->create('c.pdf', 200, 'application/pdf')])->assertCreated();

        $this->getJson('/api/v1/drivers/me/requirements')->assertJsonPath('compliance_status', 'under_review');
        $this->assertSame(ComplianceStatus::UnderReview, $this->driver->refresh()->compliance_status);
    }

    // ---------- reading one document ----------

    public function test_a_driver_sees_their_document_history_but_never_another_drivers(): void
    {
        $mine = $this->upload('clearance', [UploadedFile::fake()->image('c.jpg')])->json('data.id');
        $this->getJson("/api/v1/drivers/me/documents/{$mine}")
            ->assertOk()
            ->assertJsonPath('data.requirement.code', 'clearance')
            ->assertJsonPath('data.reviews', []);

        $other = Driver::forceCreate(['user_id' => User::factory()->driver()->create()->id]);
        $theirs = DriverDocument::forceCreate([
            'driver_id' => $other->id,
            'driver_requirement_id' => $this->requirement('clearance')->id,
            'status' => DocumentStatus::Pending,
            'is_current' => true,
        ]);
        $this->getJson("/api/v1/drivers/me/documents/{$theirs->id}")->assertNotFound();
    }

    public function test_a_passenger_cannot_upload(): void
    {
        Sanctum::actingAs(User::factory()->passenger()->create());

        $this->upload('clearance', [UploadedFile::fake()->image('c.jpg')])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }
}
