<?php

namespace App\Services;

use App\Enums\RideType;
use App\Models\FareSetting;
use App\Models\RideRequest;

/**
 * Fares (phase-0 § E.1). The ONLY place a fare is computed: the app shows what this returns, and
 * POST /rides recomputes it (a passenger can never set their own fare, § K threat #1).
 *
 *   one_way:  outbound = max(minimum_fare, base_fare + km × rate_per_km)          + service_fee
 *   two_way:  outbound + return (km × rate × return_rate_multiplier, no 2nd base)
 *             + waiting fee (max(0, minutes − free minutes) × fee per minute)      + service_fee
 *
 * Computed in centavos (whole numbers), so ₱0.10 + ₱0.20 can never become ₱0.30000000004.
 */
class FareService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** The fare rules in effect now (fare_settings is insert-only; the newest one in effect wins). */
    public function current(): FareSetting
    {
        return FareSetting::current()->firstOrFail();
    }

    /** @return list<RideType> the ride types the admin has switched on */
    public function enabledTypes(): array
    {
        return array_values(array_filter(RideType::cases(), fn (RideType $type) => $this->isEnabled($type)));
    }

    public function isEnabled(RideType $type): bool
    {
        return (bool) $this->settings->get("ride.{$type->value}_enabled", true);
    }

    /**
     * Price one ride type.
     *
     * @param  ?int  $waitMinutes  Balikan: the chosen stay (estimate) or the real waiting time (final)
     */
    public function quote(RideType $type, float $distanceKm, ?int $waitMinutes, FareSetting $fare): FareQuote
    {
        $km = round($distanceKm, 2);
        $hundredthsKm = (int) round($km * 100);
        $perKm = self::centavos($fare->rate_per_km);

        $byDistance = self::centavos($fare->base_fare) + (int) round($perKm * $hundredthsKm / 100);
        $minimum = self::centavos($fare->minimum_fare);
        $outbound = max($minimum, $byDistance);

        $twoWay = $type === RideType::TwoWay;
        $return = $twoWay ? (int) round($perKm * $hundredthsKm / 100 * (float) $fare->return_rate_multiplier) : null;

        return new FareQuote(
            rideType: $type,
            fare: $fare,
            distanceKm: $km,
            returnDistanceKm: $twoWay ? $km : null, // v1: the way back is as long as the way there
            outbound: $outbound,
            return: $return,
            waiting: $twoWay ? $this->waitingFee($waitMinutes ?? 0, $fare) : 0,
            service: self::centavos($fare->service_fee),
            minimumApplied: $minimum > $byDistance,
        );
    }

    /** Balikan waiting fee in centavos. ₱0 under the seeded rule (open decision: waiting fee). */
    public function waitingFee(int $minutes, FareSetting $fare): int
    {
        $charged = max(0, $minutes - (int) $fare->waiting_free_minutes);

        return $charged * self::centavos($fare->waiting_fee_per_minute);
    }

    /**
     * The final fare at completion: the SAME fare version the ride was booked with (a later price
     * change never alters a booked ride); only the waiting fee uses the real waiting minutes.
     */
    public function finalize(RideRequest $ride, bool $returnLeg = true): FareQuote
    {
        $fare = $ride->fareSetting;
        $quote = $this->quote($ride->ride_type, (float) $ride->outbound_distance_km, $ride->waiting_minutes, $fare);

        if ($ride->ride_type === RideType::TwoWay && ! $returnLeg) {
            // end-at-destination: the passenger didn't come back, so there's no return fare.
            return new FareQuote($quote->rideType, $fare, $quote->distanceKm, $quote->returnDistanceKm,
                $quote->outbound, 0, $quote->waiting, $quote->service, $quote->minimumApplied);
        }

        return $quote;
    }

    public static function centavos(string|float|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
