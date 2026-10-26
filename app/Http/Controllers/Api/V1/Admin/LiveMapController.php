<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\RideRequest;
use App\Services\DriverMatchingService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/admin/live (Phase 14): everything the admin's live map shows, in one poll
 * (every 10 s): drivers switched online with their last position, and the active rides.
 * Admins may see locations (phase-0 § K: "visible only to participants and admins").
 */
class LiveMapController extends Controller
{
    public function __invoke(DriverMatchingService $matching, SettingsService $settings): JsonResponse
    {
        $fresh = $matching->freshSince();
        $rides = RideRequest::query()
            ->whereIn('status', RideRequest::ACTIVE_STATUSES)
            ->with(['passenger.user', 'driver.user', 'vehicle'])
            ->orderBy('requested_at')
            ->get();
        $onRide = $rides->pluck('driver_id')->filter()->flip();

        $drivers = Driver::query()
            ->where('is_online', true)
            ->whereNotNull('current_lat')
            ->with(['user', 'activeVehicle'])
            ->get();

        return response()->json(['data' => [
            'service_area' => [
                'center_lat' => (float) $settings->get('service_area.center_lat'),
                'center_lng' => (float) $settings->get('service_area.center_lng'),
                'radius_km' => (float) $settings->get('service_area.radius_km'),
            ],
            'drivers' => $drivers->map(fn (Driver $d) => [
                'driver_id' => $d->id,
                'full_name' => $d->user->full_name,
                'plate_number' => $d->activeVehicle?->plate_number,
                'lat' => (float) $d->current_lat,
                'lng' => (float) $d->current_lng,
                'location_updated_at' => $d->location_updated_at?->toIso8601ZuluString(),
                // Online but not located lately = the server no longer matches them (stale rule).
                'matchable' => $d->location_updated_at !== null && $d->location_updated_at->gte($fresh),
                'on_ride' => $onRide->has($d->id),
            ])->values(),
            'rides' => $rides->map(fn (RideRequest $r) => [
                'id' => $r->id,
                'reference_no' => $r->reference_no,
                'status' => $r->status->value,
                'ride_type' => $r->ride_type->value,
                'current_leg' => $r->current_leg?->value,
                'passenger' => $r->passenger->user->full_name,
                'driver' => $r->driver?->user->full_name,
                'plate_number' => $r->vehicle?->plate_number,
                'pickup' => ['lat' => (float) $r->pickup_lat, 'lng' => (float) $r->pickup_lng, 'landmark' => $r->pickup_landmark],
                'destination' => ['lat' => (float) $r->destination_lat, 'lng' => (float) $r->destination_lng, 'landmark' => $r->destination_landmark],
                'route' => $r->route_points, // the road the fare used (after Phase 13); null on older rides
                'estimated_total' => $r->estimated_total,
                'requested_at' => $r->requested_at?->toIso8601ZuluString(),
            ])->values(),
        ]]);
    }
}
