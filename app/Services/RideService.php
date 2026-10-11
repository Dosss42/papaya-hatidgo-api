<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\CancelledBy;
use App\Enums\OfferStatus;
use App\Enums\RideLeg;
use App\Enums\RideStatus;
use App\Enums\RideType;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Passenger;
use App\Models\Rating;
use App\Models\RideLocation;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The ride state machine (phase-0 § E.3). The ONLY code that changes a ride's status: every
 * transition has its own method, checks where the ride is NOW (inside a locked transaction), and
 * anything else is 409 INVALID_TRANSITION naming the current status.
 *
 *   requested ─► accepted ─► driver_arriving ─► arrived ─► in_progress ──────────────► completed
 *       └──────────┴──────────────┴──────────────┴──► cancelled        (Balikan legs:
 *                                                             outbound ─► waiting ─► return)
 */
class RideService
{
    /** Balikan stay choices in minutes; empty = "Hindi pa sigurado" (booking brief, open decision #1). */
    public const WAIT_CHOICES = [15, 30, 60];

    /** "Pabalik na ako" at most once per this many minutes (active-ride brief). */
    public const RETURNING_EVERY_MINUTES = 5;

    public function __construct(
        private readonly FareService $fares,
        private readonly DriverMatchingService $matching,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    // ---------------------------------------------------------------- booking (9.2)

    /**
     * POST /rides. The gates of § E.5 step 1, in order (the subscription gate is the route's
     * `subscribed` middleware); then the fare is recomputed and matching starts.
     *
     * @param  array<string, mixed>  $data  validated StoreRideRequest data
     */
    public function create(User $user, array $data): RideRequest
    {
        if ($user->account_status !== AccountStatus::Active) {
            throw new ApiException(__('api.account_suspended'), 'ACCOUNT_SUSPENDED', 403);
        }

        $type = RideType::from($data['ride_type']);
        if (! $this->fares->isEnabled($type)) {
            throw new ApiException(__('api.ride_type_disabled'), 'RIDE_TYPE_DISABLED', 422);
        }

        $passenger = $user->passenger()->firstOrFail();
        $this->expireDueFor($passenger); // a request that ran out a second ago doesn't block a new one
        if ($active = $passenger->rideRequests()->whereIn('status', RideRequest::ACTIVE_STATUSES)->first()) {
            throw $this->activeRideExists($active);
        }

        // § K threat #1: the server computes the fare; the app's number is only compared.
        $fare = $this->fares->current();
        $km = GeoService::distanceKm((float) $data['pickup_lat'], (float) $data['pickup_lng'], (float) $data['destination_lat'], (float) $data['destination_lng']);
        $wait = $type === RideType::TwoWay ? ($data['wait_minutes'] ?? null) : null;
        $quote = $this->fares->quote($type, $km, $wait, $fare);

        if (isset($data['expected_total']) && FareService::centavos($data['expected_total']) !== $quote->total()) {
            $total = FareQuote::money($quote->total());
            throw new ApiException(
                __('api.fare_changed', ['total' => '₱'.$total]),
                'FARE_CHANGED',
                409,
                data: ['estimate' => $quote->toArray()],
            );
        }

        $ride = new RideRequest(collect($data)->only([
            'pickup_lat', 'pickup_lng', 'pickup_landmark', 'destination_lat', 'destination_lng', 'destination_landmark', 'passenger_note',
        ])->all());
        $ride->forceFill([
            'passenger_id' => $passenger->id,
            'fare_setting_id' => $fare->id,
            'ride_type' => $type,
            'wait_minutes' => $wait,
            'status' => RideStatus::Requested,
            'outbound_distance_km' => $quote->distanceKm,
            'return_distance_km' => $quote->returnDistanceKm,
            'outbound_fare' => FareQuote::money($quote->outbound),
            'return_fare' => FareQuote::money($quote->return),
            'waiting_fee' => FareQuote::money($quote->waiting),
            'service_fee' => FareQuote::money($quote->service),
            'estimated_total' => FareQuote::money($quote->total()),
            'requested_at' => now(),
            'expires_at' => now()->addSeconds((int) $this->settings->get('matching.request_timeout_seconds', 120)),
        ]);

        try {
            $ride->save();
        } catch (UniqueConstraintViolationException) {
            // active_passenger_key (N16): a double tap raced the check above; MySQL kept just one.
            throw $this->activeRideExists($passenger->rideRequests()->whereIn('status', RideRequest::ACTIVE_STATUSES)->firstOrFail());
        }

        $this->matching->dispatch($ride);

        return $ride->refresh();
    }

    // ---------------------------------------------------------------- driver transitions (9.3)

    public function depart(Driver $driver, RideRequest $ride): RideRequest
    {
        return $this->transition($driver, $ride, 'depart', fn (RideRequest $r) => $r->status === RideStatus::Accepted,
            fn (RideRequest $r) => $r->forceFill(['status' => RideStatus::DriverArriving, 'arriving_at' => now()]));
    }

    public function arrive(Driver $driver, RideRequest $ride): RideRequest
    {
        return $this->transition($driver, $ride, 'arrive', fn (RideRequest $r) => $r->status === RideStatus::DriverArriving,
            fn (RideRequest $r) => $r->forceFill(['status' => RideStatus::Arrived, 'arrived_at' => now()]));
    }

    public function start(Driver $driver, RideRequest $ride): RideRequest
    {
        return $this->transition($driver, $ride, 'start', fn (RideRequest $r) => $r->status === RideStatus::Arrived,
            fn (RideRequest $r) => $r->forceFill([
                'status' => RideStatus::InProgress,
                'started_at' => now(),
                'current_leg' => $r->ride_type === RideType::TwoWay ? RideLeg::Outbound : null,
            ]));
    }

    /** Balikan: at the destination, the driver starts waiting. */
    public function reachDestination(Driver $driver, RideRequest $ride): RideRequest
    {
        return $this->transition($driver, $ride, 'reach-destination', fn (RideRequest $r) => $this->onLeg($r, RideLeg::Outbound),
            fn (RideRequest $r) => $r->forceFill(['current_leg' => RideLeg::Waiting, 'destination_reached_at' => now()]));
    }

    /** Balikan: the passenger is back; the way home starts (waiting minutes = the two timestamps, N13). */
    public function startReturn(Driver $driver, RideRequest $ride): RideRequest
    {
        return $this->transition($driver, $ride, 'start-return', fn (RideRequest $r) => $this->onLeg($r, RideLeg::Waiting),
            fn (RideRequest $r) => $r->forceFill(['current_leg' => RideLeg::Return, 'return_started_at' => now()]));
    }

    /** One-way at the destination, or Balikan back at the pickup. Final fare set here. */
    public function complete(Driver $driver, RideRequest $ride): RideRequest
    {
        return $this->transition($driver, $ride, 'complete',
            fn (RideRequest $r) => $r->status === RideStatus::InProgress
                && ($r->ride_type === RideType::OneWay ? $r->current_leg === null : $r->current_leg === RideLeg::Return),
            fn (RideRequest $r) => $this->finish($r, returnLeg: true));
    }

    /**
     * Balikan, waiting leg, the passenger doesn't come back (E.3, "proposed", decision #4):
     * completed with outbound + waiting fee, no return fare. The reason goes to the audit log.
     */
    public function endAtDestination(Driver $driver, RideRequest $ride, string $reason): RideRequest
    {
        $ride = $this->transition($driver, $ride, 'end-at-destination', fn (RideRequest $r) => $this->onLeg($r, RideLeg::Waiting),
            fn (RideRequest $r) => $this->finish($r, returnLeg: false));
        $this->audit->record($driver->user, 'ride.ended_at_destination', $ride, null, ['reason' => $reason]);

        return $ride;
    }

    // ---------------------------------------------------------------- cancel, expiry (9.4)

    /**
     * POST /rides/{id}/cancel (decision #3):
     *   passenger: until the trip starts, reason optional
     *   driver:    an accepted ride until the trip starts, reason required (checked by the request)
     *   admin:     any active ride, reason required, audited
     */
    public function cancel(User $actor, RideRequest $ride, ?string $reason): RideRequest
    {
        $by = match ($actor->role) {
            UserRole::Passenger => CancelledBy::Passenger,
            UserRole::Driver => CancelledBy::Driver,
            UserRole::Admin => CancelledBy::Admin,
        };
        $allowed = match ($by) {
            CancelledBy::Passenger => [RideStatus::Requested, RideStatus::Accepted, RideStatus::DriverArriving, RideStatus::Arrived],
            CancelledBy::Driver => [RideStatus::Accepted, RideStatus::DriverArriving, RideStatus::Arrived],
            default => RideRequest::ACTIVE_STATUSES,
        };

        $cancelled = DB::transaction(function () use ($ride, $by, $allowed, $reason) {
            $locked = RideRequest::lockForUpdate()->findOrFail($ride->id);
            if (! in_array($locked->status, $allowed, true)) {
                throw $this->invalid($locked);
            }
            $locked->forceFill([
                'status' => RideStatus::Cancelled,
                'current_leg' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $by,
                'cancel_reason' => $reason,
            ])->save();
            $this->matching->closeOffers($locked, OfferStatus::Closed);

            return $locked;
        });

        if ($by === CancelledBy::Admin) {
            $this->audit->record($actor, 'ride.cancelled_by_admin', $cancelled, null, ['reason' => $reason]);
        }
        // "Ride cancelled" push to the other party: Phase 13.

        return $cancelled;
    }

    /**
     * No driver accepted in time → cancelled by the system, reason no_driver (E.5 #7). Race-safe:
     * the conditional UPDATE does nothing if a driver accepted a moment earlier.
     */
    public function expireIfDue(RideRequest $ride): RideRequest
    {
        if ($ride->status !== RideStatus::Requested || $ride->expires_at->isFuture()) {
            return $ride;
        }

        DB::transaction(function () use ($ride) {
            $updated = RideRequest::query()
                ->whereKey($ride->id)
                ->where('status', RideStatus::Requested)
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => RideStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => CancelledBy::System,
                    'cancel_reason' => 'no_driver',
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $this->matching->closeOffers($ride, OfferStatus::Expired);
            }
        });
        // "No driver found" push: Phase 13.

        return $ride->refresh();
    }

    /** php artisan rides:expire (every minute). @return int rides expired */
    public function expireAllDue(): int
    {
        $count = 0;
        RideRequest::query()
            ->where('status', RideStatus::Requested)
            ->where('expires_at', '<=', now())
            ->each(function (RideRequest $ride) use (&$count) {
                if ($this->expireIfDue($ride)->status === RideStatus::Cancelled) {
                    $count++;
                }
            });

        return $count;
    }

    // ---------------------------------------------------------------- during and after the ride (9.4)

    /** POST /rides/{id}/passenger-returning: tells the waiting driver; changes NO status (decision #8). */
    public function passengerReturning(RideRequest $ride): RideRequest
    {
        if (! $this->onLeg($ride, RideLeg::Waiting)) {
            throw $this->invalid($ride);
        }
        if ($ride->passenger_returning_at?->gt(now()->subMinutes(self::RETURNING_EVERY_MINUTES))) {
            throw new ApiException(__('api.returning_too_soon'), 'RETURNING_TOO_SOON', 429);
        }

        $ride->forceFill(['passenger_returning_at' => now()])->save();
        // "Pabalik na ang pasahero" push to the driver: Phase 13 (the driver's poll already shows it).

        return $ride;
    }

    /**
     * POST /rides/{id}/locations: the route trail while matched (every ~10 s, § I). The newest
     * point is also the driver's current position (what the passenger's distance line uses).
     *
     * @param  list<array{lat: float|string, lng: float|string, accuracy_m?: float|string|null, recorded_at?: string|null}>  $points
     */
    public function recordLocations(Driver $driver, RideRequest $ride, array $points): int
    {
        if (! $ride->isMatchedAndActive()) {
            throw $this->invalid($ride);
        }

        $rows = array_map(fn (array $p) => [
            'ride_request_id' => $ride->id,
            'lat' => $p['lat'],
            'lng' => $p['lng'],
            'accuracy_m' => $p['accuracy_m'] ?? null,
            'recorded_at' => isset($p['recorded_at']) ? \Illuminate\Support\Carbon::parse($p['recorded_at']) : now(),
        ], $points);
        RideLocation::insert($rows);

        $latest = end($rows);
        $driver->forceFill(['current_lat' => $latest['lat'], 'current_lng' => $latest['lng'], 'location_updated_at' => now()])->save();

        return count($rows);
    }

    /** POST /rides/{id}/rating: the passenger, a completed ride, once (UNIQUE ride_request_id). */
    public function rate(RideRequest $ride, int $score, ?string $comment): Rating
    {
        if ($ride->status !== RideStatus::Completed) {
            throw new ApiException(__('api.rate_after_completed'), 'RIDE_NOT_COMPLETED', 409);
        }

        try {
            return Rating::forceCreate(['ride_request_id' => $ride->id, 'score' => $score, 'comment' => $comment]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException(__('api.already_rated'), 'ALREADY_RATED', 409);
        }
    }

    /** The passenger's ride that just ran out of time, expired before anything reads it. */
    public function expireDueFor(Passenger $passenger): void
    {
        $passenger->rideRequests()
            ->where('status', RideStatus::Requested)
            ->where('expires_at', '<=', now())
            ->each(fn (RideRequest $r) => $this->expireIfDue($r));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * One driver transition: lock the row, check it's THIS driver's ride (404 otherwise, so a
     * stranger learns nothing), check the current status (409 otherwise), apply, save.
     */
    private function transition(Driver $driver, RideRequest $ride, string $action, callable $allowed, callable $apply): RideRequest
    {
        return DB::transaction(function () use ($driver, $ride, $allowed, $apply) {
            $locked = RideRequest::with('fareSetting')->lockForUpdate()->findOrFail($ride->id);
            if ((int) $locked->driver_id !== (int) $driver->id) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(RideRequest::class);
            }
            if (! $allowed($locked)) {
                throw $this->invalid($locked);
            }
            $apply($locked);
            $locked->save();
            // Status pushes (accepted / arriving / arrived / completed): Phase 13.

            return $locked->refresh();
        });
    }

    /** Completed: the final fare parts (final_total is MySQL's own sum, N15). */
    private function finish(RideRequest $ride, bool $returnLeg): void
    {
        $quote = $this->fares->finalize($ride, $returnLeg);
        $ride->forceFill([
            'status' => RideStatus::Completed,
            'current_leg' => null,
            'completed_at' => now(),
            'outbound_fare' => FareQuote::money($quote->outbound),
            'return_fare' => FareQuote::money($quote->return),
            'waiting_fee' => FareQuote::money($quote->waiting),
            'service_fee' => FareQuote::money($quote->service),
        ]);
    }

    private function onLeg(RideRequest $ride, RideLeg $leg): bool
    {
        return $ride->status === RideStatus::InProgress && $ride->ride_type === RideType::TwoWay && $ride->current_leg === $leg;
    }

    /** 409 naming where the ride is now (§ E.3: "a message that names the current status"). */
    private function invalid(RideRequest $ride): ApiException
    {
        $where = $ride->current_leg ? "{$ride->status->value}_{$ride->current_leg->value}" : $ride->status->value;

        return new ApiException(
            __('api.invalid_transition', ['status' => __("api.ride_status_{$where}")]),
            'INVALID_TRANSITION',
            409,
            data: ['status' => $ride->status->value, 'current_leg' => $ride->current_leg?->value],
        );
    }

    private function activeRideExists(RideRequest $active): ApiException
    {
        return new ApiException(__('api.active_ride_exists'), 'ACTIVE_RIDE_EXISTS', 409, data: ['ride_id' => $active->id]);
    }
}
