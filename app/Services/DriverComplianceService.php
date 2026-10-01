<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Enums\RequirementScope;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;
use App\Models\Vehicle;
use App\Support\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of drivers.compliance_status and of a tricycle's automatic status
 * (phase-0 § D.2, decision Phase 7 #1). Call recalculate() after anything that can change it:
 * an upload, a review, a vehicle edit, a suspension, the daily expiry job.
 *
 * Suspension is not a compliance status here: it lives in users.account_status and is reported
 * by eligibility() as its own check.
 */
class DriverComplianceService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Re-derive the driver's status from their documents and save it.
     * Also: the active tricycle's automatic status, and forcing offline when no longer verified.
     */
    public function recalculate(Driver $driver): ComplianceStatus
    {
        return DB::transaction(function () use ($driver) {
            $driver->refresh()->load('activeVehicle');
            $vehicle = $driver->activeVehicle;
            $states = $this->requirementStates($driver);

            if ($vehicle) {
                $this->syncVehicleStatus($vehicle, $states);
            }

            $status = $this->evaluate($states, $vehicle);
            $driver->compliance_status = $status;

            // A driver who stops being verified can't stay online (phase-0 § D.3 side effect).
            // The "you were set offline" notification arrives with Phase 13.
            if ($status !== ComplianceStatus::Verified) {
                $driver->is_online = false;
            }

            $driver->save();

            return $status;
        });
    }

    /**
     * The go-online checklist (phase-0 § D.3). The app shows it as-is.
     * Recalculates first, so a document that expired an hour ago already counts.
     *
     * @return array{eligible: bool, checks: list<array{key: string, passed: bool, action?: string}>}
     */
    public function eligibility(Driver $driver): array
    {
        $status = $this->recalculate($driver);
        $driver->refresh()->load(['activeVehicle', 'user']);
        $vehicle = $driver->activeVehicle;

        $checks = [
            $this->check('account_active', $driver->user->account_status === AccountStatus::Active, 'contact_admin'),
            $this->check('compliance_verified', $status === ComplianceStatus::Verified, 'complete_requirements'),
            $this->check('vehicle_verified', $vehicle?->status === VehicleStatus::Verified, $vehicle ? 'fix_vehicle' : 'add_vehicle'),
            $this->check('subscription_active', $this->subscriptions->isActive($driver->user), 'renew_subscription'),
        ];

        return [
            'eligible' => collect($checks)->every(fn (array $c) => $c['passed']),
            'checks' => $checks,
        ];
    }

    /**
     * Where the driver stands on each active, required requirement (in the admin's sort order).
     *
     * @return Collection<int, RequirementState>
     */
    public function requirementStates(Driver $driver): Collection
    {
        $driver->loadMissing('activeVehicle');
        $vehicle = $driver->activeVehicle;
        $documents = $driver->documents()->with('files')->get();

        return DriverRequirement::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (DriverRequirement $requirement) use ($documents, $vehicle) {
                $forVehicle = $requirement->applies_to === RequirementScope::Vehicle;
                if ($forVehicle && ! $vehicle) {
                    return new RequirementState($requirement, null, null, null, locked: true);
                }

                // Documents for this requirement (and, for tricycle papers, this tricycle).
                // (Compared as integers: depending on the PDO settings MySQL may return IDs as strings.)
                $vehicleId = $forVehicle ? (int) $vehicle->id : null;
                $mine = $documents->filter(fn (DriverDocument $d) => (int) $d->driver_requirement_id === (int) $requirement->id
                    && ($d->vehicle_id === null ? null : (int) $d->vehicle_id) === $vehicleId);

                $current = $mine->firstWhere('is_current', true);
                $renewal = $mine
                    ->filter(fn (DriverDocument $d) => ! $d->is_current && $d->status === DocumentStatus::Pending)
                    ->sortByDesc('id')
                    ->first();

                return new RequirementState(
                    $requirement,
                    $current,
                    $current ? $this->effectiveStatus($current) : null,
                    $renewal,
                    locked: false,
                );
            })
            ->values();
    }

    /** Approved but past its expiry date = expired, even if the daily job hasn't run yet. */
    public function effectiveStatus(DriverDocument $document): DocumentStatus
    {
        if ($document->status === DocumentStatus::Approved
            && BusinessDate::isPast($document->expires_at)) { // Philippine calendar, not UTC
            return DocumentStatus::Expired;
        }

        return $document->status;
    }

    /** phase-0 § D.2, rules 2–6. The FIRST matching rule wins. */
    private function evaluate(Collection $states, ?Vehicle $vehicle): ComplianceStatus
    {
        // 2. A critical document has expired.
        if ($states->contains(fn (RequirementState $s) => $s->status === DocumentStatus::Expired && $s->requirement->is_critical)) {
            return ComplianceStatus::Expired;
        }

        // 3. A document was rejected / needs to be resubmitted, or the admin rejected the tricycle.
        if ($states->contains(fn (RequirementState $s) => $s->needsFix()) || $vehicle?->status === VehicleStatus::Rejected) {
            return ComplianceStatus::Rejected;
        }

        // 4. Something is still missing (including: no usable tricycle yet).
        // A non-critical expired document counts as missing: it must be renewed, but it
        // doesn't make the whole account "expired".
        if ($states->contains(fn (RequirementState $s) => $s->isMissing() || $s->status === DocumentStatus::Expired)
            || $vehicle === null
            || $vehicle->status === VehicleStatus::Inactive) {
            return ComplianceStatus::PendingVerification;
        }

        // 5. Everything is submitted and at least one is waiting for the admin.
        if ($states->contains(fn (RequirementState $s) => $s->status === DocumentStatus::Pending)) {
            return ComplianceStatus::UnderReview;
        }

        // 6. Everything approved and valid (the tricycle is then verified by syncVehicleStatus).
        return $vehicle->status === VehicleStatus::Verified
            ? ComplianceStatus::Verified
            : ComplianceStatus::UnderReview;
    }

    /**
     * Decision Phase 7 #1: a tricycle is verified automatically when all of its papers
     * (OR/CR, MTOP) are approved and valid, and goes back to pending when they're not.
     * An admin's manual Rejected / Inactive is never overwritten here.
     */
    private function syncVehicleStatus(Vehicle $vehicle, Collection $states): void
    {
        if (! in_array($vehicle->status, [VehicleStatus::Pending, VehicleStatus::Verified], true)) {
            return;
        }

        $papersOk = $states
            ->filter(fn (RequirementState $s) => $s->requirement->applies_to === RequirementScope::Vehicle)
            ->every(fn (RequirementState $s) => $s->status === DocumentStatus::Approved);

        $target = $papersOk ? VehicleStatus::Verified : VehicleStatus::Pending;
        if ($vehicle->status !== $target) {
            $vehicle->status = $target;
            $vehicle->save();
        }
    }

    /** @return array{key: string, passed: bool, action?: string} */
    private function check(string $key, bool $passed, string $action): array
    {
        return $passed ? ['key' => $key, 'passed' => true] : ['key' => $key, 'passed' => false, 'action' => $action];
    }
}
