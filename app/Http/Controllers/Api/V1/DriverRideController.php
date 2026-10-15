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

        // meta.is_online (Phase 11): whether the SERVER still has this driver online. The scheduler
        // can switch a driver off (stale location, expired papers or subscription); the app must
        // then stop looking online (driver-home brief § 5). meta.server_now: the app measures the
        // countdown against the SERVER's clock, so a phone whose clock is off still shows the real
        // time left (and never hides a live request as "expired").
        return RideOfferResource::collection($open->values())
            ->additional(['meta' => [
                'is_online' => (bool) $driver->refresh()->is_online,
                'server_now' => now()->toIso8601ZuluString(),
            ]]);
    }

    /**
     * GET /drivers/me/earnings?period=day|week|month (Phase 11): cash from COMPLETED rides, on the
     * Philippine calendar (today, Monday–Sunday, this month). The totals are MySQL's own
     * final_total sums (N15), so they can't disagree with the receipts.
     */
    public function earnings(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['nullable', 'in:day,week,month']])['period'] ?? 'day';
        $zone = config('app.business_timezone');
        $now = now($zone);
        [$from, $to] = match ($period) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(\Carbon\CarbonInterface::MONDAY), $now->copy()->endOfWeek(\Carbon\CarbonInterface::SUNDAY)],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };

        $completed = RideRequest::query()
            ->where('driver_id', $this->driver($request)->id)
            ->where('status', RideStatus::Completed)
            ->whereBetween('completed_at', [$from->copy()->utc(), $to->copy()->utc()]);

        $total = (clone $completed)->sum('final_total');

        return response()->json(['data' => [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => number_format((float) $total, 2, '.', ''),
            'rides_count' => (clone $completed)->count(),
            'rides' => (clone $completed)->latest('completed_at')->limit(50)->get()->map(fn (RideRequest $r) => [
                'id' => $r->id,
                'reference_no' => $r->reference_no,
                'ride_type' => $r->ride_type->value,
                'total' => $r->final_total,
                'completed_at' => $r->completed_at->toIso8601ZuluString(),
            ])->all(),
        ]]);
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
