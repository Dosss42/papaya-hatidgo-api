<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RideType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rides\FareEstimateRequest;
use App\Services\FareService;
use App\Services\GeoService;
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
     * POST /fare/estimate: every ENABLED type priced at once, so the booking sheet can show
     * "One-way ₱62 · Balikan ₱94" side by side (booking brief § 4, step 3).
     */
    public function estimate(FareEstimateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $fare = $this->fares->current();
        $km = GeoService::distanceKm((float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['destination_lat'], (float) $data['destination_lng']);

        return response()->json(['data' => [
            'distance_km' => number_format(round($km, 2), 2, '.', ''),
            'options' => array_map(
                fn (RideType $type) => $this->fares->quote($type, $km, $data['wait_minutes'] ?? null, $fare)->toArray(),
                $this->fares->enabledTypes(),
            ),
        ]]);
    }
}
