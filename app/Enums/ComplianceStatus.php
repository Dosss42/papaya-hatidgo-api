<?php

namespace App\Enums;

/**
 * Mirrors drivers.compliance_status: document-based states only
 * (suspension is AccountStatus). Written only by DriverComplianceService (Phase 7).
 */
enum ComplianceStatus: string
{
    case PendingVerification = 'pending_verification';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
