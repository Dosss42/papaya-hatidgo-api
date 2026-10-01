<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Enums\VehicleStatus;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirementReview;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * A driver's tricycle (phase-0 § G, Phase 7 step 7.2).
 * Every change ends with DriverComplianceService::recalculate(), the only writer of the statuses.
 */
class VehicleService
{
    public function __construct(private readonly DriverComplianceService $compliance) {}

    /**
     * Register a tricycle. The first one becomes the driver's active tricycle (decision Phase 7 #3:
     * one tricycle in the app; the API allows more for later).
     *
     * @param  array<string, mixed>  $data  validated plate_number, body_number, color, make, model
     */
    public function create(Driver $driver, array $data): Vehicle
    {
        $this->assertActive($driver);

        return DB::transaction(function () use ($driver, $data) {
            $vehicle = new Vehicle($data);
            $vehicle->driver_id = $driver->id;
            $vehicle->status = VehicleStatus::Pending; // verified later, automatically, from its papers
            $vehicle->save();

            if ($driver->active_vehicle_id === null) {
                $driver->active_vehicle_id = $vehicle->id;
                $driver->save();
            }

            $this->compliance->recalculate($driver);

            return $vehicle->refresh();
        });
    }

    /**
     * Change the tricycle's details. A NEW PLATE means its OR/CR and MTOP no longer prove anything
     * (they show the old plate), so they're voided with a reason and the tricycle goes back to
     * pending until new papers are approved (brief § 4.3). Color, body number, make, model: no re-review.
     *
     * @param  array<string, mixed>  $data  only the fields that were sent
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        $driver = $vehicle->driver;
        $this->assertActive($driver);

        return DB::transaction(function () use ($vehicle, $driver, $data) {
            $plateChanged = array_key_exists('plate_number', $data) && $data['plate_number'] !== $vehicle->plate_number;

            $vehicle->fill($data);
            if ($plateChanged && $vehicle->status !== VehicleStatus::Inactive) {
                $vehicle->status = VehicleStatus::Pending;
                $this->voidPapers($vehicle);
            }
            $vehicle->save();

            $this->compliance->recalculate($driver);

            return $vehicle->refresh();
        });
    }

    /** The tricycle's current papers (approved or still waiting) → "resubmission required", with why. */
    private function voidPapers(Vehicle $vehicle): void
    {
        $papers = DriverDocument::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('is_current', true)
            ->whereIn('status', [DocumentStatus::Approved, DocumentStatus::Pending])
            ->get();

        foreach ($papers as $document) {
            $document->status = DocumentStatus::ResubmissionRequired;
            $document->save();

            DriverRequirementReview::forceCreate([
                'driver_document_id' => $document->id,
                'reviewer_id' => null, // the system acted (allowed by chk_review_actor since 2026_10_01)
                'action' => ReviewAction::InvalidatedBySystem,
                'reason' => __('api.reason_plate_changed'),
            ]);
        }
    }

    /** A suspended driver can still SEE their status, but can't change anything. */
    private function assertActive(Driver $driver): void
    {
        if ($driver->user->account_status === AccountStatus::Suspended) {
            throw new ApiException(__('api.account_suspended'), 'ACCOUNT_SUSPENDED', 403);
        }
    }
}
