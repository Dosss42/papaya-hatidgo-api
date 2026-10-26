<?php

namespace App\Services;

/** A trip's distance and line (RoutingService): along the roads, or the straight line × the detour factor. */
final class TripRoute
{
    /**
     * @param  list<array{0: float, 1: float}>  $points  [[lat, lng], …]
     */
    public function __construct(
        public readonly float $km,
        public readonly bool $byRoad,
        public readonly array $points,
    ) {}
}
