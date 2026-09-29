<?php

namespace App\Enums;

/** Mirrors ride_requests.cancelled_by. */
enum CancelledBy: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
    case System = 'system';
    case Admin = 'admin';
}
