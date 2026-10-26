<?php

namespace App\Http\Resources;

use App\Models\Driver;
use App\Models\RideRequest;
use App\Services\GeoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open offer, for the driver's full-screen request alert (driver-home brief § 7): everything
 * needed to decide in a few seconds, and NOTHING personal (no passenger name or phone).
 * The request board (after Phase 13) shows open requests in the same shape, with offer_id null.
 * Load with: rideRequest, driver.
 */
class RideOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::shape($this->id, $this->rideRequest, $this->driver);
    }

    /**
     * One open request as the driver sees it. $offerId is null on the board (no offer was made
     * to this driver: they found it in the list).
     *
     * @return array<string, mixed>
     */
    public static function shape(?int $offerId, RideRequest $ride, Driver $driver): array
    {
        return [
            'offer_id' => $offerId,
            'ride_id' => $ride->id,
            'expires_at' => $ride->expires_at->toIso8601ZuluString(), // the REAL countdown, not a fake one
            'ride_type' => $ride->ride_type->value,
            'wait_minutes' => $ride->wait_minutes,
            'estimated_total' => $ride->estimated_total,
            'distance_km' => $ride->outbound_distance_km,
            'pickup' => [
                'lat' => $ride->pickup_lat,
                'lng' => $ride->pickup_lng,
                'landmark' => $ride->pickup_landmark,
                // "Sundo: 0.8 km mula sa'yo"
                'distance_from_you_km' => $driver->current_lat === null ? null : round(GeoService::distanceKm(
                    (float) $driver->current_lat, (float) $driver->current_lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng,
                ), 2),
            ],
            'destination' => ['lat' => $ride->destination_lat, 'lng' => $ride->destination_lng, 'landmark' => $ride->destination_landmark],
            'passenger_note' => $ride->passenger_note,
        ];
    }
}
