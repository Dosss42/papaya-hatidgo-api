<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverRequirementReview;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * The daily expiry job (phase-0 § D.1 "approved → expired when expires_at passes", § D.2 rule 2).
 *
 * The app already TREATS an approved document past its date as expired (effectiveStatus), so
 * nobody stays verified for even an hour. This job makes it TRUE IN THE DATABASE: it marks the
 * document expired, writes the history entry (by the system), and recalculates the driver
 * (which sets them offline and returns the tricycle to pending if it was a tricycle paper).
 *
 * Safe to run any number of times: an already-expired document isn't touched again.
 * Pending documents are left alone: the admin can't approve an expired one (DOCUMENT_EXPIRED)
 * and will reject it; the driver then uploads a new one.
 */
class DocumentExpiryService
{
    public function __construct(
        private readonly DriverComplianceService $compliance,
        private readonly AuditService $audit,
    ) {}

    /** @return array{documents: int, drivers: int} what was done (the command prints it) */
    public function expireOverdue(): array
    {
        $today = BusinessDate::todayString(); // Philippine calendar
        $driverIds = [];
        $count = 0;

        DriverDocument::query()
            ->where('status', DocumentStatus::Approved)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $today) // valid THROUGH the expiry date
            ->orderBy('id')
            ->chunkById(200, function ($documents) use (&$driverIds, &$count) {
                foreach ($documents as $document) {
                    DB::transaction(function () use ($document) {
                        $document->status = DocumentStatus::Expired;
                        $document->save();

                        DriverRequirementReview::forceCreate([
                            'driver_document_id' => $document->id,
                            'reviewer_id' => null, // the system (allowed only for system actions: chk_review_actor)
                            'action' => ReviewAction::ExpiredBySystem,
                            'reason' => null,
                        ]);
                        $this->audit->record(null, 'driver_document.expired', $document,
                            ['status' => 'approved'], ['status' => 'expired', 'expires_at' => $document->expires_at?->toDateString()]);
                    });

                    $driverIds[$document->driver_id] = true;
                    $count++;
                }
            });

        // Once per affected driver, after all their documents are updated.
        Driver::whereKey(array_keys($driverIds))->get()->each(fn (Driver $d) => $this->compliance->recalculate($d));

        return ['documents' => $count, 'drivers' => count($driverIds)];
    }
}
