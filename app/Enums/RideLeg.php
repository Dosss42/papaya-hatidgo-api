<?php

namespace App\Enums;

/** Mirrors ride_requests.current_leg (Balikan only, while in_progress). */
enum RideLeg: string
{
    case Outbound = 'outbound';
    case Waiting = 'waiting';
    case Return = 'return';
}
