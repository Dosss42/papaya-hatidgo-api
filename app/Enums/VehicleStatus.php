<?php

namespace App\Enums;

/** Mirrors vehicles.status. */
enum VehicleStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Inactive = 'inactive';
}
