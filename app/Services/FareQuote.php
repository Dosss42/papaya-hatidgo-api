<?php

namespace App\Services;

use App\Enums\RideType;
use App\Models\FareSetting;

/**
 * One priced ride type (FareService). Money is kept in CENTAVOS (whole numbers) while computing,
 * and turned into "62.00" strings only for the database and the API.
 */
final readonly class FareQuote
{
    public function __construct(
        public RideType $rideType,
        public FareSetting $fare,
        public float $distanceKm,       // outbound, 2 decimals
        public ?float $returnDistanceKm,
        public int $outbound,           // centavos
        public ?int $return,
        public int $waiting,
        public int $service,
        public bool $minimumApplied,
    ) {}

    public function total(): int
    {
        return $this->outbound + ($this->return ?? 0) + $this->waiting + $this->service;
    }

    public static function money(?int $centavos): ?string
    {
        return $centavos === null ? null : number_format($centavos / 100, 2, '.', '');
    }

    /** The breakdown the booking sheet shows ("Tingnan ang detalye"). */
    public function toArray(): array
    {
        return [
            'ride_type' => $this->rideType->value,
            'distance_km' => number_format($this->distanceKm, 2, '.', ''),
            'return_distance_km' => $this->returnDistanceKm === null ? null : number_format($this->returnDistanceKm, 2, '.', ''),
            'outbound_fare' => self::money($this->outbound),
            'return_fare' => self::money($this->return),
            'waiting_fee' => self::money($this->waiting),
            'service_fee' => self::money($this->service),
            'total' => self::money($this->total()),
            'breakdown' => [
                'base_fare' => $this->fare->base_fare,
                'rate_per_km' => $this->fare->rate_per_km,
                'minimum_fare' => $this->fare->minimum_fare,
                'minimum_applied' => $this->minimumApplied,
                'return_rate_multiplier' => $this->fare->return_rate_multiplier,
                'waiting_free_minutes' => $this->fare->waiting_free_minutes,
                'waiting_fee_per_minute' => $this->fare->waiting_fee_per_minute,
            ],
        ];
    }
}
