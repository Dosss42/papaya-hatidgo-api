<?php

namespace App\Enums;

/** Mirrors ride_requests.status. Transitions are enforced by RideService (phase-0 § E.3). */
enum RideStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case DriverArriving = 'driver_arriving';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
