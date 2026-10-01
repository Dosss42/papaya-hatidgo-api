<?php

namespace Tests\Feature\Drivers;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Enums\VehicleStatus;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverComplianceService;
use Database\Seeders\DriverRequirementSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The daily expiry job: php artisan documents:expire (Phase 7 step 7.5). */
class DocumentExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DriverRequirementSeeder::class);
    }

    /** A verified, online driver whose four papers expire on the given dates. */
    private function verifiedDriver(array $expiresByCode): Driver
    {
        $driver = Driver::forceCreate(['user_id' => User::factory()->driver()->create()->id]);
        $vehicle = Vehicle::forceCreate(['driver_id' => $driver->id, 'plate_number' => 'ABC'.$driver->id.'00', 'color' => 'Pula']);
        $driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();

        foreach (DriverRequirement::all() as $requirement) {
            DriverDocument::forceCreate([
                'driver_id' => $driver->id,
                'driver_requirement_id' => $requirement->id,
                'vehicle_id' => $requirement->applies_to->value === 'vehicle' ? $vehicle->id : null,
                'status' => DocumentStatus::Approved,
                'is_current' => true,
                'expires_at' => $expiresByCode[$requirement->code] ?? '2030-01-01',
            ]);
        }
        app(DriverComplianceService::class)->recalculate($driver);
        $driver->refresh()->forceFill(['is_online' => true])->save();

        return $driver;
    }

    private function document(Driver $driver, string $code): DriverDocument
    {
        return DriverDocument::where('driver_id', $driver->id)
            ->where('driver_requirement_id', DriverRequirement::where('code', $code)->value('id'))
            ->firstOrFail();
    }

    public function test_an_overdue_document_expires_with_history_audit_and_the_driver_set_offline(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 03:00', 'UTC'));
        $driver = $this->verifiedDriver(['clearance' => '2026-10-05']);

        $this->artisan('documents:expire')
            ->expectsOutputToContain('1 document(s) expired, 1 driver(s) recalculated')
            ->assertSuccessful();

        $clearance = $this->document($driver, 'clearance')->load('latestReview');
        $this->assertSame(DocumentStatus::Expired, $clearance->status);
        $this->assertSame(ReviewAction::ExpiredBySystem, $clearance->latestReview->action);
        $this->assertNull($clearance->latestReview->reviewer_id);

        $log = AuditLog::where('action', 'driver_document.expired')->firstOrFail();
        $this->assertNull($log->actor_id); // the system

        $driver->refresh();
        $this->assertSame(ComplianceStatus::Expired, $driver->compliance_status);
        $this->assertFalse($driver->is_online);
    }

    public function test_an_expired_tricycle_paper_returns_the_tricycle_to_pending(): void
    {
        // Verified while the OR/CR is still valid (Oct 1)… (created later, the app would already
        // treat the overdue paper as expired: the "effective status" rule from step 7.1)
        $this->travelTo(Carbon::parse('2026-09-30 03:00', 'UTC'));
        $driver = $this->verifiedDriver(['or_cr' => '2026-10-01']);
        $this->assertSame(VehicleStatus::Verified, $driver->activeVehicle->status);

        // …then the date passes and the daily job runs.
        $this->travelTo(Carbon::parse('2026-10-10 03:00', 'UTC'));
        $this->artisan('documents:expire')->assertSuccessful();

        $this->assertSame(VehicleStatus::Pending, $driver->activeVehicle->refresh()->status);
    }

    public function test_valid_and_pending_documents_are_left_alone_and_a_second_run_does_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 03:00', 'UTC'));
        $driver = $this->verifiedDriver(['clearance' => '2026-10-05']);
        $this->document($driver, 'mtop_permit')->forceFill(['status' => DocumentStatus::Pending, 'expires_at' => '2026-09-01'])->save();

        $this->artisan('documents:expire')->expectsOutputToContain('1 document(s) expired')->assertSuccessful();
        $this->assertSame(DocumentStatus::Pending, $this->document($driver, 'mtop_permit')->status);
        $this->assertSame(DocumentStatus::Approved, $this->document($driver, 'drivers_license')->status);

        $this->artisan('documents:expire')->expectsOutputToContain('0 document(s) expired, 0 driver(s)')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('action', 'driver_document.expired')->count());
    }

    public function test_expiry_follows_the_philippine_calendar_not_utc(): void
    {
        // 15:30 UTC on Oct 1 = 23:30 Oct 1 in Manila: a document expiring Oct 1 is still valid.
        $this->travelTo(Carbon::parse('2026-10-01 15:30', 'UTC'));
        $driver = $this->verifiedDriver(['clearance' => '2026-10-01']);
        $this->artisan('documents:expire')->expectsOutputToContain('Today (2026-10-01')->assertSuccessful();
        $this->assertSame(DocumentStatus::Approved, $this->document($driver, 'clearance')->status);
        $this->assertSame(ComplianceStatus::Verified, $driver->refresh()->compliance_status);

        // 16:30 UTC on Oct 1 = 00:30 Oct 2 in Manila: now it has expired, even though UTC still says Oct 1.
        $this->travelTo(Carbon::parse('2026-10-01 16:30', 'UTC'));
        $this->artisan('documents:expire')->expectsOutputToContain('Today (2026-10-02')->assertSuccessful();
        $this->assertSame(DocumentStatus::Expired, $this->document($driver, 'clearance')->status);
    }

    public function test_the_job_is_scheduled_daily_at_0005_philippine_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'documents:expire'));

        $this->assertNotNull($event, 'documents:expire is not scheduled');
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone);
    }
}
