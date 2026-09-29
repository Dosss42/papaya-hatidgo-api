<?php

namespace App\Enums;

/** Mirrors driver_documents.status (the document's current state). */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case ResubmissionRequired = 'resubmission_required';
}
