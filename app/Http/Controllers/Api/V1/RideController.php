<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RideStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rides\StoreRideRequest;
use App\Http\Resources\RideResource;
use App\Models\RideLocation;
use App\Models\RideRequest;
use App\Services\DriverMatchingService;
use App\Services\RideService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Rides as their participants use them (Phase 9). Every {ride} lookup is scoped to the caller's
 * own rides (§ K IDOR): someone else's ride answers 404, never 403, so ids can't be probed.
 */
class RideController extends Controller
{
    private const RELATIONS = ['passenger.user', 'driver.user', 'vehicle', 'rating'];

    public function __construct(
        private readonly RideService $rides,
        private readonly DriverMatchingService $matching,
    ) {}

    /** POST /rides (passenger, subscribed): book. */
    public function store(StoreRideRequest $request): JsonResponse
    {
        $ride = $this->rides->create($request->user(), $request->validated());

        return (new RideResource($ride->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * GET /rides/{id}: the polling target (every 3–5 s). A request that ran out of time is
     * expired here (E.5 #7), and a passenger's poll tops up the offers (decision #1).
     */
    public function show(Request $request, int $ride): RideResource
    {
        $found = $this->rides->expireIfDue($this->mine($request, $ride));
        if ($found->status === RideStatus::Requested && $request->user()->role === UserRole::Passenger) {
            $this->matching->dispatch($found);
        }

        return new RideResource($found->load(self::RELATIONS));
    }

    /** GET /rides/active: the ride to resume when the app opens (or null). */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === UserRole::Passenger) {
            $this->rides->expireDueFor($user->passenger()->firstOrFail());
        }

        $ride = $this->scope($request)->whereIn('status', RideRequest::ACTIVE_STATUSES)->with(self::RELATIONS)->first();

        return response()->json(['data' => $ride ? new RideResource($ride) : null]);
    }

    /** GET /rides: my history, newest first (20 per page). */
    public function index(Request $request): AnonymousResourceCollection
    {
        return RideResource::collection(
            $this->scope($request)->with(self::RELATIONS)->latest('id')->simplePaginate(20),
        );
    }

    /** POST /rides/{id}/cancel: passenger (reason optional) or the assigned driver (reason required). */
    public function cancel(Request $request, int $ride): RideResource
    {
        $isDriver = $request->user()->role === UserRole::Driver;
        $reason = $request->validate([
            'reason' => [$isDriver ? 'required' : 'nullable', 'string', 'min:2', 'max:100'],
        ])['reason'] ?? null;

        return new RideResource($this->rides->cancel($request->user(), $this->mine($request, $ride), $reason)->load(self::RELATIONS));
    }

    /** POST /rides/{id}/rating (passenger): 1–5 stars, once, after completion. */
    public function rate(Request $request, int $ride): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $rating = $this->rides->rate($this->mine($request, $ride), $data['score'], $data['comment'] ?? null);

        return response()->json(['data' => ['score' => $rating->score, 'comment' => $rating->comment]], 201);
    }

    /** POST /rides/{id}/passenger-returning (passenger, Balikan waiting leg): "Pabalik na ako". */
    public function passengerReturning(Request $request, int $ride): RideResource
    {
        return new RideResource($this->rides->passengerReturning($this->mine($request, $ride))->load(self::RELATIONS));
    }

    /** The driven path is thinned to at most this many points (a phone on mobile data, decision #6). */
    public const TRAIL_MAX_POINTS = 300;

    /**
     * GET /rides/{id}/trail (Phase 12): the path of the TRIP (from "Simulan ang biyahe" on, so the
     * driver's way to the pickup, where they were before, isn't in the passenger's record), oldest
     * first, for the ride's own passenger or driver (or an admin). A long ride is thinned evenly,
     * always keeping the first and the last point, so the line still starts and ends where it did.
     */
    public function trail(Request $request, int $ride): JsonResponse
    {
        $found = $this->mine($request, $ride); // 404 for anyone else's ride
        $points = RideLocation::query()
            ->where('ride_request_id', $found->id)
            ->when(
                $found->started_at,
                fn ($q) => $q->where('recorded_at', '>=', $found->started_at),
                fn ($q) => $q->whereRaw('1 = 0'), // never started: no trip, no path
            )
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['lat', 'lng', 'recorded_at']);

        $total = $points->count();
        if ($total > self::TRAIL_MAX_POINTS) {
            $step = ($total - 1) / (self::TRAIL_MAX_POINTS - 1);
            $points = collect(range(0, self::TRAIL_MAX_POINTS - 1))->map(fn (int $i) => $points[(int) round($i * $step)]);
        }

        return response()->json(['data' => [
            'total_points' => $total,
            'points' => $points->map(fn (RideLocation $p) => [
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'recorded_at' => $p->recorded_at->toIso8601ZuluString(),
            ])->values(),
        ]]);
    }

    /** The caller's rides: as passenger, as assigned driver, or every ride for an admin. */
    private function scope(Request $request): Builder
    {
        $user = $request->user();

        return match ($user->role) {
            UserRole::Passenger => RideRequest::query()->where('passenger_id', $user->passenger()->value('id')),
            UserRole::Driver => RideRequest::query()->where('driver_id', $user->driver()->value('id')),
            UserRole::Admin => RideRequest::query(),
        };
    }

    private function mine(Request $request, int $id): RideRequest
    {
        return $this->scope($request)->findOrFail($id);
    }
}
