<?php

namespace App\Enums;

/** Mirrors driver_requirement_reviews.action (one event in a document's review history). */
enum ReviewAction: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ResubmissionRequested = 'resubmission_requested';
    case ExpiredBySystem = 'expired_by_system';
}
