<?php

namespace App\Enums;

/** Mirrors driver_requirement_reviews.action (one event in a document's review history). */
enum ReviewAction: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ResubmissionRequested = 'resubmission_requested';
    case ExpiredBySystem = 'expired_by_system';
    // Phase 7: the system voided the document because something it proves changed
    // (e.g. the tricycle's plate number). Always carries a reason.
    case InvalidatedBySystem = 'invalidated_by_system';
}
