<?php

namespace App\Enums;

/** Mirrors ride_requests.ride_type (UI: "One-way" / "Balikan"). */
enum RideType: string
{
    case OneWay = 'one_way';
    case TwoWay = 'two_way';
}
