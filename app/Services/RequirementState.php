<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\DriverDocument;
use App\Models\DriverRequirement;

/**
 * Where ONE driver stands on ONE requirement right now (computed, never stored).
 *
 * - $current: the document that counts (is_current = 1), or null if nothing was submitted.
 * - $status: the EFFECTIVE status: an approved document whose expires_at has passed counts as
 *   Expired even before the daily job has marked it, so no driver stays "verified" for hours on
 *   an expired license. Null = missing.
 * - $pendingRenewal: a newer upload waiting for review while an approved one still counts.
 * - $locked: a tricycle document can't be uploaded yet because there is no tricycle.
 */
final readonly class RequirementState
{
    public function __construct(
        public DriverRequirement $requirement,
        public ?DriverDocument $current,
        public ?DocumentStatus $status,
        public ?DriverDocument $pendingRenewal,
        public bool $locked,
    ) {}

    public function isMissing(): bool
    {
        return $this->status === null;
    }

    /** Rejected and "please resubmit" mean the same to the driver: read the reason, fix, upload. */
    public function needsFix(): bool
    {
        return in_array($this->status, [DocumentStatus::Rejected, DocumentStatus::ResubmissionRequired], true);
    }
}
