<?php

namespace App\Http\Resources;

use App\Services\GeoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open offer, for the driver's full-screen request alert (driver-home brief § 7): everything
 * needed to decide in a few seconds, and NOTHING personal (no passenger name or phone).
 * Load with: rideRequest, driver.
 */
class RideOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ride = $this->rideRequest;
        $driver = $this->driver;

        return [
            'offer_id' => $this->id,
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
