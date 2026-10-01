<?php

namespace Tests\Feature\Drivers;

use App\Enums\AccountStatus;
use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DriverComplianceService;
use Database\Seeders\DriverRequirementSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DriverComplianceService: phase-0 § D.2 rules, renewal, automatic tricycle status, § D.3 eligibility. */
class DriverComplianceTest extends TestCase
{
    use RefreshDatabase;

    private DriverComplianceService $compliance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DriverRequirementSeeder::class); // the 4 real requirements
        $this->compliance = app(DriverComplianceService::class);
    }

    // ---------- helpers ----------

    private function driver(bool $withTricycle = true): Driver
    {
        $user = User::factory()->driver()->create();
        $driver = Driver::forceCreate(['user_id' => $user->id]);

        if ($withTricycle) {
            $vehicle = Vehicle::forceCreate([
                'driver_id' => $driver->id,
                'plate_number' => 'ABC'.fake()->unique()->numerify('####'),
                'color' => 'Pula',
            ]);
            $driver->forceFill(['active_vehicle_id' => $vehicle->id])->save();
        }

        return $driver->refresh();
    }

    private function upload(Driver $driver, string $code, DocumentStatus $status, ?string $expires = '+1 year', bool $current = true): DriverDocument
    {
        $requirement = DriverRequirement::where('code', $code)->firstOrFail();
        $isVehiclePaper = in_array($code, ['or_cr', 'mtop_permit'], true);

        return DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'driver_requirement_id' => $requirement->id,
            'vehicle_id' => $isVehiclePaper ? $driver->active_vehicle_id : null,
            'status' => $status,
            'is_current' => $current,
            'expires_at' => $expires ? now()->modify($expires)->toDateString() : null,
        ]);
    }

    private function uploadAll(Driver $driver, DocumentStatus $status): void
    {
        foreach (['drivers_license', 'or_cr', 'mtop_permit', 'clearance'] as $code) {
            $this->upload($driver, $code, $status);
        }
    }

    // ---------- § D.2 rules ----------

    public function test_a_new_driver_is_pending_verification(): void
    {
        $this->assertSame(ComplianceStatus::PendingVerification, $this->compliance->recalculate($this->driver()));
    }

    public function test_all_four_submitted_is_under_review(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Pending);

        $this->assertSame(ComplianceStatus::UnderReview, $this->compliance->recalculate($driver));
    }

    public function test_all_approved_is_verified_and_the_tricycle_is_verified_automatically(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);

        $this->assertSame(ComplianceStatus::Verified, $this->compliance->recalculate($driver));
        $this->assertSame(VehicleStatus::Verified, $driver->activeVehicle->refresh()->status);
        $this->assertSame(ComplianceStatus::Verified, $driver->refresh()->compliance_status); // saved
    }

    public function test_a_rejected_document_wins_over_missing_ones(): void
    {
        $driver = $this->driver();
        $this->upload($driver, 'clearance', DocumentStatus::Rejected); // the other 3 are missing

        $this->assertSame(ComplianceStatus::Rejected, $this->compliance->recalculate($driver));
    }

    public function test_resubmission_required_counts_as_rejected(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        DriverDocument::where('driver_id', $driver->id)->first()->forceFill(['status' => DocumentStatus::ResubmissionRequired])->save();

        $this->assertSame(ComplianceStatus::Rejected, $this->compliance->recalculate($driver));
    }

    public function test_an_approved_document_past_its_date_counts_as_expired_before_the_daily_job(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        DriverDocument::where('driver_id', $driver->id)->first()->forceFill(['expires_at' => now()->subDay()->toDateString()])->save();

        $this->assertSame(ComplianceStatus::Expired, $this->compliance->recalculate($driver));
    }

    public function test_expired_wins_over_rejected(): void
    {
        $driver = $this->driver();
        $this->upload($driver, 'drivers_license', DocumentStatus::Expired);
        $this->upload($driver, 'clearance', DocumentStatus::Rejected);

        $this->assertSame(ComplianceStatus::Expired, $this->compliance->recalculate($driver));
    }

    public function test_tricycle_papers_are_locked_without_a_tricycle(): void
    {
        $driver = $this->driver(withTricycle: false);
        $this->upload($driver, 'drivers_license', DocumentStatus::Approved);
        $this->upload($driver, 'clearance', DocumentStatus::Approved);

        $states = $this->compliance->requirementStates($driver)->keyBy(fn ($s) => $s->requirement->code);
        $this->assertTrue($states['or_cr']->locked);
        $this->assertTrue($states['mtop_permit']->locked);
        $this->assertFalse($states['drivers_license']->locked);
        $this->assertSame(ComplianceStatus::PendingVerification, $this->compliance->recalculate($driver));
    }

    public function test_an_admin_rejected_tricycle_blocks_verification_and_is_not_auto_overwritten(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        $driver->activeVehicle->forceFill(['status' => VehicleStatus::Rejected])->save();

        $this->assertSame(ComplianceStatus::Rejected, $this->compliance->recalculate($driver));
        $this->assertSame(VehicleStatus::Rejected, $driver->activeVehicle->refresh()->status);
    }

    // ---------- renewal (brief § 5) ----------

    public function test_a_pending_renewal_keeps_the_driver_verified(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        $renewal = $this->upload($driver, 'drivers_license', DocumentStatus::Pending, '+2 years', current: false);

        $this->assertSame(ComplianceStatus::Verified, $this->compliance->recalculate($driver));
        $license = $this->compliance->requirementStates($driver)->first(fn ($s) => $s->requirement->code === 'drivers_license');
        $this->assertSame($renewal->id, $license->pendingRenewal?->id);
    }

    // ---------- side effects + eligibility (§ D.3) ----------

    public function test_a_driver_who_stops_being_verified_is_set_offline(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        $this->compliance->recalculate($driver);
        $driver->forceFill(['is_online' => true])->save();

        DriverDocument::where('driver_id', $driver->id)->first()->forceFill(['status' => DocumentStatus::Rejected])->save();
        $this->compliance->recalculate($driver);

        $this->assertFalse($driver->refresh()->is_online);
    }

    public function test_eligibility_lists_every_check_and_the_next_action(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);

        $result = $this->compliance->eligibility($driver);

        $this->assertFalse($result['eligible']);
        $this->assertSame(
            ['account_active' => true, 'compliance_verified' => true, 'vehicle_verified' => true, 'subscription_active' => false],
            collect($result['checks'])->mapWithKeys(fn ($c) => [$c['key'] => $c['passed']])->all(),
        );
        $this->assertSame('renew_subscription', $result['checks'][3]['action']);
    }

    public function test_eligible_when_verified_active_and_subscribed(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        Subscription::forceCreate([
            'user_id' => $driver->user_id,
            'subscription_plan_id' => SubscriptionPlan::where('code', 'drv_1m')->value('id'),
            'state' => 'paid',
            'amount' => 199,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->assertTrue($this->compliance->eligibility($driver)['eligible']);
    }

    public function test_a_suspended_driver_fails_the_account_check(): void
    {
        $driver = $this->driver();
        $this->uploadAll($driver, DocumentStatus::Approved);
        $driver->user->forceFill(['account_status' => AccountStatus::Suspended])->save();

        $checks = collect($this->compliance->eligibility($driver)['checks'])->keyBy('key');

        $this->assertFalse($checks['account_active']['passed']);
        $this->assertSame('contact_admin', $checks['account_active']['action']);
    }
}
