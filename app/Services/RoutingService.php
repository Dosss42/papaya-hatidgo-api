<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The trip's distance ALONG THE ROADS (the student's request after Phase 13: the straight line
 * made fares too low). Asks OpenRouteService (free key, OpenStreetMap roads, the same map data
 * as the MapTiler tiles), on the SERVER: the fare is never computed from what a phone says.
 *
 * - One answer per pair of points is kept for a day, so the estimate and the booking a minute
 *   later use the same route and the same fare (no false "the fare changed").
 * - No key, no internet, or no road found: the straight line × FALLBACK (a typical detour on a
 *   town's roads), marked byRoad = false. Booking never stops because a routing service is down.
 */
class RoutingService
{
    private const URL = 'https://api.openrouteservice.org/v2/directions/driving-car/geojson';

    /** Roads are about 30% longer than the straight line in a small town (and never shorter). */
    public const FALLBACK = 1.3;

    /** A long trip's line is thinned to this many points: enough for the map, small for the phone. */
    public const MAX_POINTS = 300;

    public function __construct(
        private readonly ?string $key,
        private readonly float $fallbackFactor = self::FALLBACK,
    ) {}

    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): TripRoute
    {
        $straight = GeoService::distanceKm($fromLat, $fromLng, $toLat, $toLng);
        $fallback = new TripRoute(round($straight * $this->fallbackFactor, 2), false, [[$fromLat, $fromLng], [$toLat, $toLng]]);
        if ($this->key === null || $this->key === '') {
            return $fallback;
        }

        $cacheKey = 'route:'.implode(',', array_map(fn (float $v) => round($v, 5), [$fromLat, $fromLng, $toLat, $toLng]));
        try {
            // Only a real answer is kept: a failure throws, and Cache::remember stores nothing.
            $road = Cache::remember($cacheKey, now()->addDay(), fn () => $this->ask($fromLat, $fromLng, $toLat, $toLng));

            return new TripRoute($road['km'], true, $road['points']);
        } catch (Throwable $e) {
            Log::warning('Road route unavailable; using the straight line x '.$this->fallbackFactor, ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /** @return array{km: float, points: list<array{0: float, 1: float}>} */
    private function ask(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $response = Http::withHeaders(['Authorization' => $this->key])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(6)
            ->post(self::URL, [
                'coordinates' => [[$fromLng, $fromLat], [$toLng, $toLat]], // GeoJSON order: lng, lat
                // A pin may sit in a yard or a field: look for the nearest road within 1 km.
                'radiuses' => [1000, 1000],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('OpenRouteService answered HTTP '.$response->status().': '.$response->json('error.message', ''));
        }

        $meters = $response->json('features.0.properties.summary.distance');
        $line = $response->json('features.0.geometry.coordinates');
        if (! is_numeric($meters) || ! is_array($line) || count($line) < 2) {
            throw new RuntimeException('OpenRouteService answered without a route');
        }

        return ['km' => round($meters / 1000, 2), 'points' => self::thin(array_map(fn (array $c) => [(float) $c[1], (float) $c[0]], $line))];
    }

    /**
     * Keep every n-th point (and always the last), so a long road stays a few hundred points.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    public static function thin(array $points): array
    {
        $count = count($points);
        if ($count <= self::MAX_POINTS) {
            return $points;
        }
        $step = (int) ceil($count / (self::MAX_POINTS - 1));
        $kept = [];
        for ($i = 0; $i < $count - 1; $i += $step) {
            $kept[] = $points[$i];
        }
        $kept[] = $points[$count - 1];

        return $kept;
    }
}
