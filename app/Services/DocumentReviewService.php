<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Exceptions\ApiException;
use App\Models\DriverDocument;
use App\Models\DriverRequirementReview;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An admin's decision on one submitted document (phase-0 § D.4).
 * Each decision = one review row (what + why + who) + one audit_logs row + recalculate().
 * Only a PENDING document can be decided; a decision is final (a new upload starts a new review).
 * Telling the driver by notification comes with Phase 13; until then the checklist shows it.
 */
class DocumentReviewService
{
    public function __construct(
        private readonly DriverComplianceService $compliance,
        private readonly AuditService $audit,
    ) {}

    /**
     * Approve. The admin may correct the expiry date read from the photo.
     * A renewal becomes the current document only now, on approval (brief § 5).
     */
    public function approve(User $admin, DriverDocument $document, ?string $expiresAt = null): DriverDocument
    {
        return $this->decide($admin, $document, ReviewAction::Approved, DocumentStatus::Approved, null,
            function (DriverDocument $doc) use ($expiresAt) {
                if ($expiresAt !== null) {
                    $doc->expires_at = Carbon::parse($expiresAt);
                }
                if ($doc->requirement->requires_expiry && $doc->expires_at === null) {
                    throw new ApiException(__('api.expiry_required_to_approve'), 'EXPIRY_REQUIRED', 422);
                }
                if (BusinessDate::isPast($doc->expires_at)) {
                    throw new ApiException(__('api.document_already_expired'), 'DOCUMENT_EXPIRED', 422);
                }

                if (! $doc->is_current) {
                    // The approved renewal takes over: the old one stops counting (it stays
                    // "approved" in the history, it just isn't the current one anymore).
                    DriverDocument::query()
                        ->where('driver_id', $doc->driver_id)
                        ->where('driver_requirement_id', $doc->driver_requirement_id)
                        ->where(fn ($q) => $doc->vehicle_id === null ? $q->whereNull('vehicle_id') : $q->where('vehicle_id', $doc->vehicle_id))
                        ->where('is_current', true)
                        ->update(['is_current' => false]); // first, for the "one current" UNIQUE index
                    $doc->is_current = true;
                }
            });
    }

    public function reject(User $admin, DriverDocument $document, string $reason): DriverDocument
    {
        return $this->decide($admin, $document, ReviewAction::Rejected, DocumentStatus::Rejected, $reason);
    }

    public function requestResubmission(User $admin, DriverDocument $document, string $reason): DriverDocument
    {
        return $this->decide($admin, $document, ReviewAction::ResubmissionRequested, DocumentStatus::ResubmissionRequired, $reason);
    }

    /**
     * The common steps of every decision. Rejecting a RENEWAL leaves the approved current
     * document in place, so the driver stays eligible while they upload a better copy.
     */
    private function decide(
        User $admin,
        DriverDocument $document,
        ReviewAction $action,
        DocumentStatus $newStatus,
        ?string $reason,
        ?callable $extra = null,
    ): DriverDocument {
        $document = DB::transaction(function () use ($admin, $document, $action, $newStatus, $reason, $extra) {
            $doc = DriverDocument::with('requirement')->lockForUpdate()->findOrFail($document->id);
            if ($doc->status !== DocumentStatus::Pending) {
                throw new ApiException(__('api.document_not_pending'), 'DOCUMENT_NOT_PENDING', 409);
            }

            $old = $this->snapshot($doc);
            if ($extra) {
                $extra($doc);
            }
            $doc->status = $newStatus;
            $doc->save();

            DriverRequirementReview::forceCreate([
                'driver_document_id' => $doc->id,
                'reviewer_id' => $admin->id,
                'action' => $action,
                'reason' => $reason,
            ]);
            $this->audit->record($admin, "driver_document.{$action->value}", $doc, $old, $this->snapshot($doc) + ['reason' => $reason]);

            return $doc;
        });

        $this->compliance->recalculate($document->driver);

        return $document->refresh()->load(['requirement', 'files', 'reviews.reviewer', 'driver.user', 'vehicle']);
    }

    /** @return array<string, mixed> */
    private function snapshot(DriverDocument $doc): array
    {
        return [
            'status' => $doc->status->value,
            'is_current' => $doc->is_current,
            'expires_at' => $doc->expires_at?->toDateString(),
        ];
    }
}
