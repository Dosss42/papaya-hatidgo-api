<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OfferStatus;
use App\Enums\RideStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideOfferResource;
use App\Http\Resources\RideResource;
use App\Models\Driver;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Services\DriverMatchingService;
use App\Services\RideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The driver's side of a ride (Phase 9 step 9.3): offers, accept / decline, the transitions,
 * the route trail, and the idle location ping that matching uses.
 */
class DriverRideController extends Controller
{
    private const RELATIONS = ['passenger.user', 'driver.user', 'vehicle', 'rating'];

    public function __construct(
        private readonly RideService $rides,
        private readonly DriverMatchingService $matching,
    ) {}

    /** GET /drivers/me/offers (polled every 5 s while online): my open offers, newest first. */
    public function offers(Request $request): AnonymousResourceCollection
    {
        $driver = $this->driver($request);
        $open = RideOffer::query()
            ->where('driver_id', $driver->id)
            ->where('status', OfferStatus::Offered)
            ->with(['rideRequest', 'driver'])
            ->latest('id')
            ->get()
            ->map(fn (RideOffer $o) => tap($o, fn () => $this->rides->expireIfDue($o->rideRequest)))
            ->filter(fn (RideOffer $o) => $o->rideRequest->status === RideStatus::Requested);

        return RideOfferResource::collection($open->values());
    }

    public function accept(Request $request, int $ride): RideResource
    {
        return $this->show($this->matching->accept($this->driver($request), RideRequest::findOrFail($ride)));
    }

    public function decline(Request $request, int $ride): JsonResponse
    {
        $this->matching->decline($this->driver($request), RideRequest::findOrFail($ride));

        return response()->json(['data' => ['declined' => true]]);
    }

    public function depart(Request $request, int $ride): RideResource
    {
        return $this->show($this->rides->depart($this->driver($request), RideRequest::findOrFail($ride)));
    }

    public function arrive(Request $request, int $ride): RideResource
    {
        return $this->show($this->rides->arrive($this->driver($request), RideRequest::findOrFail($ride)));
    }

    public function start(Request $request, int $ride): RideResource
    {
        return $this->show($this->rides->start($this->driver($request), RideRequest::findOrFail($ride)));
    }

    public function reachDestination(Request $request, int $ride): RideResource
    {
        return $this->show($this->rides->reachDestination($this->driver($request), RideRequest::findOrFail($ride)));
    }

    public function startReturn(Request $request, int $ride): RideResource
    {
        return $this->show($this->rides->startReturn($this->driver($request), RideRequest::findOrFail($ride)));
    }

    public function complete(Request $request, int $ride): RideResource
    {
        return $this->show($this->rides->complete($this->driver($request), RideRequest::findOrFail($ride)));
    }

    /** Balikan: the passenger didn't come back (reason required, audited). */
    public function endAtDestination(Request $request, int $ride): RideResource
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']])['reason'];

        return $this->show($this->rides->endAtDestination($this->driver($request), RideRequest::findOrFail($ride), $reason));
    }

    /** POST /rides/{id}/locations: the route trail while matched (batches of up to 50 points). */
    public function locations(Request $request, int $ride): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:50'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'points.*.recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $driver = $this->driver($request);
        $owned = RideRequest::query()->where('driver_id', $driver->id)->findOrFail($ride); // 404 if not theirs

        return response()->json(['data' => ['saved' => $this->rides->recordLocations($driver, $owned, $data['points'])]], 201);
    }

    /**
     * POST /drivers/me/location: the idle ping while online (every 60 s or 100 m, § I). Matching
     * only considers drivers located in the last 3 minutes (the stale-online rule).
     */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $driver = $this->driver($request);
        if (! $driver->is_online) {
            throw new ApiException(__('api.not_online'), 'NOT_ONLINE', 409);
        }

        $driver->forceFill(['current_lat' => $data['lat'], 'current_lng' => $data['lng'], 'location_updated_at' => now()])->save();

        return response()->json(['data' => ['location_updated_at' => $driver->location_updated_at->toIso8601ZuluString()]]);
    }

    private function show(RideRequest $ride): RideResource
    {
        return new RideResource($ride->load(self::RELATIONS));
    }

    private function driver(Request $request): Driver
    {
        return $request->user()->driver()->firstOrFail();
    }
}
