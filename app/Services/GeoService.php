<?php

namespace App\Services;

/**
 * Distances and the service area (phase-0 § I). The SERVER computes every distance; the app
 * never sends one. v1 uses straight-line (Haversine) distance: no road routing in the MVP.
 */
class GeoService
{
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(private readonly SettingsService $settings) {}

    /** Great-circle distance in km between two points (the Haversine formula). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Inside the service area: a circle (center + radius) in system_settings, a PLACEHOLDER until
     * the exact town boundary is confirmed (open decision #15).
     */
    public function insideServiceArea(float $lat, float $lng): bool
    {
        $centerLat = (float) $this->settings->get('service_area.center_lat', 0);
        $centerLng = (float) $this->settings->get('service_area.center_lng', 0);
        $radiusKm = (float) $this->settings->get('service_area.radius_km', 0);

        return self::distanceKm($centerLat, $centerLng, $lat, $lng) <= $radiusKm;
    }
}
