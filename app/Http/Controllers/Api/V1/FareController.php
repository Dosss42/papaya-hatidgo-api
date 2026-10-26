<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RideType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rides\FareEstimateRequest;
use App\Services\FareService;
use App\Services\RideService;
use App\Services\RoutingService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/** Ride types and the fare estimate (Phase 9 step 9.1, phase-0 § E.1). */
class FareController extends Controller
{
    public function __construct(private readonly FareService $fares) {}

    /** GET /ride-types: which types the admin has switched on (a disabled card isn't shown at all). */
    public function rideTypes(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (RideType $type) => ['ride_type' => $type->value, 'enabled' => $this->fares->isEnabled($type)],
            RideType::cases(),
        )]);
    }

    /**
     * GET /booking-options (Phase 10): what the booking screen needs before asking for a fare, so
     * a pin outside the area or too close is flagged at once (booking brief E1/E2). The server still
     * checks it all again on /fare/estimate and /rides.
     */
    public function bookingOptions(SettingsService $settings): JsonResponse
    {
        return response()->json(['data' => [
            'ride_types' => array_map(fn (RideType $t) => $t->value, $this->fares->enabledTypes()),
            'service_area' => [
                'center_lat' => (float) $settings->get('service_area.center_lat', 0),
                'center_lng' => (float) $settings->get('service_area.center_lng', 0),
                'radius_km' => (float) $settings->get('service_area.radius_km', 0),
            ],
            'min_distance_m' => (int) $settings->get('ride.min_distance_m', 100),
            'wait_choices' => RideService::WAIT_CHOICES,
            'request_timeout_seconds' => (int) $settings->get('matching.request_timeout_seconds', 120),
        ]]);
    }

    /**
     * POST /fare/estimate: every ENABLED type priced at once, so the booking sheet can show
     * "One-way ₱62 · Balikan ₱94" side by side (booking brief § 4, step 3).
     */
    public function estimate(FareEstimateRequest $request, RoutingService $routing): JsonResponse
    {
        $data = $request->validated();
        $fare = $this->fares->current();
        // Along the roads (after Phase 13); POST /rides a moment later reuses the same route.
        $route = $routing->route((float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['destination_lat'], (float) $data['destination_lng']);
        $km = $route->km;

        return response()->json(['data' => [
            'distance_km' => number_format(round($km, 2), 2, '.', ''),
            'by_road' => $route->byRoad,
            'route' => $route->points, // [[lat, lng], …]: the line the map draws
            'options' => array_map(
                fn (RideType $type) => $this->fares->quote($type, $km, $data['wait_minutes'] ?? null, $fare)->toArray(),
                $this->fares->enabledTypes(),
            ),
        ]]);
    }
}
