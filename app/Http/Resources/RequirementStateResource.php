<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use App\Services\RequirementState;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the driver's checklist (GET /drivers/me/requirements): the requirement, where the
 * driver stands on it, and what the app needs to show the brief's chip and detail line.
 *
 * @property RequirementState $resource
 */
class RequirementStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->resource;
        $current = $state->current;

        return [
            'requirement' => new RequirementResource($state->requirement),
            // missing | pending | approved | rejected | resubmission_required | expired (EFFECTIVE status)
            'status' => $state->status?->value ?? 'missing',
            'locked' => $state->locked, // tricycle papers before there is a tricycle
            'document' => $current === null ? null : [
                'id' => $current->id,
                'document_number' => $current->document_number,
                'expires_at' => $current->expires_at?->toDateString(),
                'submitted_at' => $current->submitted_at?->toIso8601String(),
                // For "Mag-e-expire sa {n} araw" (approved only; negative = already past).
                'days_until_expiry' => $state->status === DocumentStatus::Approved && $current->expires_at
                    ? BusinessDate::daysUntil($current->expires_at)
                    : null,
                // The admin's (or the system's) reason, shown when the driver must fix something.
                'reason' => $state->needsFix() ? $current->latestReview?->reason : null,
            ],
            'renewal' => $state->pendingRenewal === null ? null : [
                'id' => $state->pendingRenewal->id,
                'submitted_at' => $state->pendingRenewal->submitted_at?->toIso8601String(),
            ],
        ];
    }
}
