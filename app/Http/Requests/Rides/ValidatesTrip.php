<?php

namespace App\Http\Requests\Rides;

use App\Services\GeoService;
use App\Services\SettingsService;
use Illuminate\Validation\Validator;

/**
 * The pickup / destination rules shared by POST /fare/estimate and POST /rides (Phase 9).
 * Errors are attached to the pin that is wrong, so the app can mark that pin (booking brief E1, E2).
 */
trait ValidatesTrip
{
    /** @return array<string, mixed> */
    protected function tripRules(): array
    {
        return [
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /** Runs after the basic rules pass: service area, then minimum distance. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $geo = app(GeoService::class);
                [$pLat, $pLng, $dLat, $dLng] = array_map('floatval', [
                    $this->input('pickup_lat'), $this->input('pickup_lng'),
                    $this->input('destination_lat'), $this->input('destination_lng'),
                ]);

                if (! $geo->insideServiceArea($pLat, $pLng)) {
                    $validator->errors()->add('pickup_lat', __('api.outside_service_area'));
                }
                if (! $geo->insideServiceArea($dLat, $dLng)) {
                    $validator->errors()->add('destination_lat', __('api.outside_service_area'));
                }

                $minMeters = (int) app(SettingsService::class)->get('ride.min_distance_m', 100);
                if ($validator->errors()->isEmpty() && GeoService::distanceKm($pLat, $pLng, $dLat, $dLng) * 1000 < $minMeters) {
                    $validator->errors()->add('destination_lat', __('api.too_close'));
                }
            },
        ];
    }
}
