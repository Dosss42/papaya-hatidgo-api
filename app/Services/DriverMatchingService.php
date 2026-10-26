<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ComplianceStatus;
use App\Enums\OfferStatus;
use App\Enums\RideStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\RideOffer;
use App\Models\RideRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matching v1, "nearby broadcast" (phase-0 § E.5): a request goes to the N nearest ELIGIBLE
 * drivers at once, and the FIRST one to accept gets it.
 *
 * Eligible = online · located within the last 3 minutes · verified papers · verified tricycle ·
 * active subscription · account active · not on another ride · within matching.radius_km.
 */
class DriverMatchingService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SubscriptionService $subscriptions,
        private readonly DriverComplianceService $compliance,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Top up the open offers to matching.max_offers (decision #1): at booking, after a decline,
     * and on each passenger poll, so a driver who comes online meanwhile still gets the request.
     *
     * @return int how many new offers were made
     */
    public function dispatch(RideRequest $ride): int
    {
        if ($ride->status !== RideStatus::Requested || $ride->expires_at->isPast()) {
            return 0;
        }

        $open = $ride->offers()->where('status', OfferStatus::Offered)->count();
        $needed = (int) $this->settings->get('matching.max_offers', 5) - $open;
        if ($needed <= 0) {
            return 0;
        }

        $alreadyAsked = $ride->offers()->pluck('driver_id')->all(); // never offer the same ride twice
        $offered = [];
        foreach ($this->nearestEligible($ride, $alreadyAsked, $needed) as $driver) {
            try {
                RideOffer::forceCreate(['ride_request_id' => $ride->id, 'driver_id' => $driver->id, 'status' => OfferStatus::Offered]);
                $offered[] = $driver;
            } catch (UniqueConstraintViolationException) {
                // Two polls topped up at the same moment: this driver already has the offer.
            }
        }
        // A ringing push for the drivers just offered (their app also polls every 5 s).
        $this->notifications->newOffer($ride, $offered);

        return count($offered);
    }

    /**
     * Eligible drivers, nearest first (MATCH-01). The database narrows the list with the cheap,
     * indexed conditions; distance and the subscription are then checked in PHP (a small town
     * has tens of drivers, not thousands).
     *
     * @param  list<int>  $exclude
     * @return Collection<int, Driver>
     */
    public function nearestEligible(RideRequest $ride, array $exclude, int $limit): Collection
    {
        $radiusKm = (float) $this->settings->get('matching.radius_km', 3);

        return Driver::query()
            ->where('is_online', true)
            ->where('compliance_status', ComplianceStatus::Verified)
            ->where('location_updated_at', '>=', $this->freshSince())
            ->whereNotNull('current_lat')
            ->whereNotIn('id', $exclude)
            ->whereHas('user', fn ($q) => $q->where('account_status', AccountStatus::Active))
            ->whereHas('activeVehicle', fn ($q) => $q->where('status', VehicleStatus::Verified))
            ->whereDoesntHave('rideRequests', fn ($q) => $q->whereIn('status', RideRequest::ACTIVE_STATUSES))
            ->with('user')
            ->get()
            ->filter(fn (Driver $d) => $this->subscriptions->isActive($d->user))
            ->map(function (Driver $d) use ($ride) {
                $d->setAttribute('distance_km', GeoService::distanceKm(
                    (float) $d->current_lat, (float) $d->current_lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng,
                ));

                return $d;
            })
            ->filter(fn (Driver $d) => $d->distance_km <= $radiusKm)
            ->sortBy('distance_km')
            ->take($limit)
            ->values();
    }

    /**
     * POST /rides/{id}/accept: race-safe (MATCH-02, § K threat #2). The winner is decided by ONE
     * conditional UPDATE: only a ride still "requested" can become "accepted". Two drivers can't
     * both win, because the second UPDATE finds status = accepted and changes 0 rows.
     */
    public function accept(Driver $driver, RideRequest $ride): RideRequest
    {
        $offer = $ride->offers()->where('driver_id', $driver->id)->first()
            ?? $this->offerFromBoard($driver, $ride);
        if (! $offer) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(RideRequest::class); // not for you: 404
        }
        if ($ride->status !== RideStatus::Requested || $ride->expires_at->isPast()) {
            throw $this->gone($ride);
        }
        if ($offer->status !== OfferStatus::Offered) {
            // Declined ("Laktawan") or closed: a skipped request can't be grabbed later.
            throw new ApiException(__('api.offer_gone'), 'OFFER_GONE', 409);
        }

        // Checked at the moment of accepting, not only when the offer was made (decision #9).
        if (! $this->canTakeRides($driver)) {
            throw new ApiException(__('api.cannot_accept'), 'CANNOT_ACCEPT', 403);
        }

        try {
            $won = DB::transaction(function () use ($driver, $ride) {
                $updated = RideRequest::query()
                    ->whereKey($ride->id)
                    ->where('status', RideStatus::Requested)
                    ->where('expires_at', '>', now())
                    ->update([
                        'status' => RideStatus::Accepted,
                        'driver_id' => $driver->id,
                        'vehicle_id' => $driver->active_vehicle_id,
                        'accepted_at' => now(),
                        'updated_at' => now(),
                    ]);
                if ($updated === 1) {
                    $this->closeOffers($ride, OfferStatus::Closed);
                }

                return $updated === 1;
            });
        } catch (UniqueConstraintViolationException) {
            // active_driver_key (N16): this driver already has an active ride.
            throw new ApiException(__('api.driver_busy'), 'DRIVER_BUSY', 409);
        }

        if (! $won) {
            throw $this->gone($ride->refresh());
        }
        $this->notifications->rideAccepted($ride->refresh());

        return $ride;
    }

    // ---------------------------------------------------------------- the request board (after Phase 13)

    /**
     * The request board (the student's hybrid dispatch): open requests within
     * matching.board_radius_km (default 5) of an online, located driver, nearest first, so the
     * driver can CHOOSE a ride. The nearest drivers still get the ringing alert (dispatch()).
     * Requests this driver already answered (skipped or missed) are left out, and so are the ones
     * offered to them (those come with the alert).
     *
     * @return Collection<int, RideRequest> each with distance_km set
     */
    public function boardFor(Driver $driver): Collection
    {
        if (! $this->mayUseBoard($driver)) {
            return collect();
        }
        $reachKm = $this->boardReachKm();

        return RideRequest::query()
            ->where('status', RideStatus::Requested)
            ->where('expires_at', '>', now())
            ->whereDoesntHave('offers', fn ($q) => $q->where('driver_id', $driver->id))
            ->get()
            ->map(function (RideRequest $ride) use ($driver) {
                $ride->setAttribute('distance_km', GeoService::distanceKm(
                    (float) $driver->current_lat, (float) $driver->current_lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng,
                ));

                return $ride;
            })
            ->filter(fn (RideRequest $ride) => $ride->distance_km <= $reachKm)
            ->sortBy('distance_km')
            ->take(20)
            ->values();
    }

    /**
     * Accepting a ride found on the board: it gets its offer row now (the record of who was
     * asked, and what the race-safe accept below needs). Only for an open request within reach
     * of an eligible driver; anything else stays a 404.
     */
    private function offerFromBoard(Driver $driver, RideRequest $ride): ?RideOffer
    {
        if (! $this->mayUseBoard($driver)) {
            return null;
        }
        $km = GeoService::distanceKm((float) $driver->current_lat, (float) $driver->current_lng, (float) $ride->pickup_lat, (float) $ride->pickup_lng);
        if ($km > $this->boardReachKm()) {
            return null;
        }
        if ($ride->status !== RideStatus::Requested || $ride->expires_at->isPast()) {
            throw $this->gone($ride); // it WAS on this driver's board: say "taken" / "gone", not "not found"
        }

        try {
            return RideOffer::forceCreate(['ride_request_id' => $ride->id, 'driver_id' => $driver->id, 'status' => OfferStatus::Offered]);
        } catch (UniqueConstraintViolationException) {
            return $ride->offers()->where('driver_id', $driver->id)->first(); // made a moment ago by another request
        }
    }

    /** Online, located recently, verified, with a verified tricycle and no ride: the cheap checks (accept() re-checks everything). */
    private function mayUseBoard(Driver $driver): bool
    {
        $driver->refresh()->loadMissing('activeVehicle');

        return $driver->is_online
            && $driver->current_lat !== null
            && $driver->location_updated_at?->gte($this->freshSince())
            && $driver->compliance_status === ComplianceStatus::Verified
            && $driver->activeVehicle?->status === VehicleStatus::Verified
            && ! $driver->rideRequests()->whereIn('status', RideRequest::ACTIVE_STATUSES)->exists();
    }

    private function boardReachKm(): float
    {
        return (float) $this->settings->get('matching.board_radius_km', 5);
    }

    /** POST /rides/{id}/decline ("Laktawan"): recorded, and the next nearest driver is offered (E.5 #6). */
    public function decline(Driver $driver, RideRequest $ride): void
    {
        $updated = $ride->offers()
            ->where('driver_id', $driver->id)
            ->where('status', OfferStatus::Offered)
            ->update(['status' => OfferStatus::Declined, 'responded_at' => now()]);

        if ($updated === 0 && ! $ride->offers()->where('driver_id', $driver->id)->exists()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(RideRequest::class);
        }

        $this->dispatch($ride->refresh());
    }

    /** Open offers end when the ride is taken, cancelled, completed (closed) or expires (expired). */
    public function closeOffers(RideRequest $ride, OfferStatus $as): void
    {
        RideOffer::query()
            ->where('ride_request_id', $ride->id)
            ->where('status', OfferStatus::Offered)
            ->update(['status' => $as, 'responded_at' => now()]);
    }

    /** Online, located recently, and passing every go-online check (Phase 8 eligibility). */
    public function canTakeRides(Driver $driver): bool
    {
        $driver->refresh();
        if (! $driver->is_online || ! $driver->location_updated_at || $driver->location_updated_at->lt($this->freshSince())) {
            return false;
        }

        return $this->compliance->eligibility($driver)['eligible'];
    }

    /** The stale-online rule (driver-home brief § 7): older than this = not really online. */
    public function freshSince(): \Carbon\CarbonInterface
    {
        return now()->subMinutes((int) $this->settings->get('matching.stale_online_minutes', 3));
    }

    /** Taken by someone else → RIDE_TAKEN; cancelled or expired → OFFER_GONE ("Wala na ang request na ito"). */
    private function gone(RideRequest $ride): ApiException
    {
        return $ride->status === RideStatus::Accepted || ($ride->driver_id !== null && $ride->isActive())
            ? new ApiException(__('api.ride_taken'), 'RIDE_TAKEN', 409)
            : new ApiException(__('api.offer_gone'), 'OFFER_GONE', 409);
    }
}
