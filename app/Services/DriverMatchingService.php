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
        $made = 0;
        foreach ($this->nearestEligible($ride, $alreadyAsked, $needed) as $driver) {
            try {
                RideOffer::forceCreate(['ride_request_id' => $ride->id, 'driver_id' => $driver->id, 'status' => OfferStatus::Offered]);
                $made++;
            } catch (UniqueConstraintViolationException) {
                // Two polls topped up at the same moment: this driver already has the offer.
            }
        }
        // "New ride offer" push to these drivers: Phase 13 (the driver app polls meanwhile).

        return $made;
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
        $offer = $ride->offers()->where('driver_id', $driver->id)->first();
        if (! $offer) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(RideRequest::class); // not offered to you: 404
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
        // "Driver accepted" push to the passenger: Phase 13 (the passenger app polls meanwhile).

        return $ride->refresh();
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
