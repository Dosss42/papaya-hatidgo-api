<?php

namespace App\Enums;

/** Mirrors subscription_plans.user_type: which role a plan is for. */
enum PlanAudience: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
}
