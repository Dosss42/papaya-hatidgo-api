<?php

namespace App\Services;

use App\Enums\CancelledBy;
use App\Enums\ReviewAction;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\BusinessDate;
use Throwable;

/**
 * Who is told what, for every event of phase-0 § J (Phase 13). The services that change things
 * (rides, matching, reviews, payments…) call ONE method here after their change is saved; this
 * class decides the recipients, the words (by kind) and the delivery:
 *
 *   in the Alerts list + push   outcomes that still matter later (completed, cancelled, papers…)
 *   push only                   live ride steps and requests, stale within minutes (decision #4)
 *
 * Nothing here can fail a ride: pushes go out after the response (FcmChannel), and the screens
 * keep polling no matter what (phase-0 § J, "the ride flow never depends on push").
 */
class NotificationService
{
    // ---------------------------------------------------------------- rides

    /** A new request for these drivers: a ringing push that disappears with the request (TTL). */
    public function newOffer(RideRequest $ride, iterable $drivers): void
    {
        $push = new AppNotification('ride_offer', [
            'fare' => $ride->estimated_total,
            'pickup' => (string) $ride->pickup_landmark,
        ], 'home', inList: false, channel: AppNotification::RIDES,
            ttlSeconds: (int) max(1, now()->diffInSeconds($ride->expires_at, false)), tag: "ride-{$ride->id}");

        foreach ($drivers as $driver) {
            $this->send($driver->loadMissing('user')->user, $push);
        }
    }

    public function rideAccepted(RideRequest $ride): void
    {
        $this->toPassenger($ride, 'ride_accepted', inList: false);
    }

    public function driverArriving(RideRequest $ride): void
    {
        $this->toPassenger($ride, 'ride_arriving', inList: false);
    }

    public function driverArrived(RideRequest $ride): void
    {
        $this->toPassenger($ride, 'ride_arrived', inList: false);
    }

    public function rideCompleted(RideRequest $ride): void
    {
        $this->toPassenger($ride, 'ride_completed', ['total' => $ride->final_total ?? $ride->estimated_total]);
    }

    /** No driver accepted in time (cancelled by the system, no_driver). */
    public function noDriverFound(RideRequest $ride): void
    {
        $this->toPassenger($ride, 'ride_no_driver');
    }

    /** The OTHER party learns about a cancel; an admin's cancel tells both. */
    public function rideCancelled(RideRequest $ride, CancelledBy $by, ?string $reason): void
    {
        $params = $reason !== null && $reason !== '' ? ['reason' => $reason] : [];

        if ($by === CancelledBy::Driver || $by === CancelledBy::Admin) {
            $this->toPassenger($ride, $by === CancelledBy::Driver ? 'ride_cancelled_by_driver' : 'ride_cancelled_by_admin', $params);
        }
        if (($by === CancelledBy::Passenger || $by === CancelledBy::Admin) && $ride->driver_id !== null) {
            $this->toDriver($ride, $by === CancelledBy::Passenger ? 'ride_cancelled_by_passenger' : 'ride_cancelled_by_admin', $params);
        }
    }

    /** "Pabalik na ako" (Balikan, waiting leg): the waiting driver gets ready. */
    public function passengerReturning(RideRequest $ride): void
    {
        $this->toDriver($ride, 'passenger_returning', inList: false);
    }

    // ---------------------------------------------------------------- driver papers and work

    public function documentDecided(DriverDocument $document, ReviewAction $action, ?string $reason): void
    {
        $kind = match ($action) {
            ReviewAction::Approved => 'document_approved',
            ReviewAction::Rejected => 'document_rejected',
            ReviewAction::ResubmissionRequested => 'document_resubmission',
            default => null,
        };
        if ($kind !== null) {
            $this->toDocumentOwner($document, $kind, $reason !== null ? ['reason' => $reason] : []);
        }
    }

    /** Reminder (ReminderService): 30 or 7 days before the expiry date, once per window. */
    public function documentExpiring(DriverDocument $document, int $window): void
    {
        $this->toDocumentOwner($document, 'document_expiring', ['date' => BusinessDate::format($document->expires_at)],
            ['document_id' => $document->id, 'window' => $window]);
    }

    public function documentExpired(DriverDocument $document): void
    {
        $this->toDocumentOwner($document, 'document_expired', [], ['document_id' => $document->id]);
    }

    /** The server switched an online driver off: no location for 3 minutes, or papers no longer valid. */
    public function driverSetOffline(Driver $driver, string $why): void
    {
        $this->send($driver->loadMissing('user')->user, new AppNotification(
            $why === 'stale' ? 'driver_offline_stale' : 'driver_offline_papers', [],
            $why === 'stale' ? 'home' : 'requirements',
        ));
    }

    // ---------------------------------------------------------------- subscriptions

    public function subscriptionActivated(Subscription $subscription): void
    {
        $this->toSubscriber($subscription, 'subscription_activated', ['date' => BusinessDate::format($subscription->ends_at)]);
    }

    public function subscriptionEnding(Subscription $subscription): void
    {
        $this->toSubscriber($subscription, 'subscription_ending', ['date' => BusinessDate::format($subscription->ends_at)],
            ['subscription_id' => $subscription->id]);
    }

    public function subscriptionEnded(Subscription $subscription): void
    {
        $this->toSubscriber($subscription, 'subscription_ended', ['date' => BusinessDate::format($subscription->ends_at)],
            ['subscription_id' => $subscription->id]);
    }

    /** A checkout that expired unpaid, or a payment of the wrong amount: in the list, no push (§ J #13). */
    public function paymentProblem(Subscription $subscription, string $kind): void
    {
        $this->send($subscription->loadMissing('user')->user, new AppNotification(
            $kind, ['amount' => $subscription->amount], 'subscription', push: false,
        ));
    }

    // ---------------------------------------------------------------- reminders sent once

    /**
     * Was this reminder already sent? The notifications table is the record (decision #8):
     * the same kind about the same thing (document + window, subscription) means yes.
     *
     * @param  array<string, int|string>  $ref
     */
    public function alreadySent(User $user, string $kind, array $ref): bool
    {
        return $user->notifications()->where('type', $kind)->get(['data'])
            ->contains(fn ($n) => array_intersect_assoc($ref, $n->data['ref'] ?? []) == $ref);
    }

    // ---------------------------------------------------------------- helpers

    private function toPassenger(RideRequest $ride, string $kind, array $params = [], bool $inList = true): void
    {
        $ride->loadMissing(['passenger.user', 'driver.user', 'vehicle']);
        $this->send($ride->passenger->user, $this->rideNotification($ride, $kind, $params, $inList));
    }

    private function toDriver(RideRequest $ride, string $kind, array $params = [], bool $inList = true): void
    {
        $ride->loadMissing(['driver.user']);
        $this->send($ride->driver?->user, $this->rideNotification($ride, $kind, $params, $inList));
    }

    /**
     * Ride pushes share the Rides channel and a tag, so each step replaces the last one (decision #6).
     * The driver's name, plate and color exist whenever a text uses them: those texts are sent only
     * after a driver accepted, which sets driver_id and vehicle_id, and all three are NOT NULL columns.
     */
    private function rideNotification(RideRequest $ride, string $kind, array $params, bool $inList): AppNotification
    {
        $params += array_filter([
            'driver' => $ride->driver?->user?->first_name,
            'plate' => $ride->vehicle?->plate_number,
            'color' => $ride->vehicle?->color,
        ], fn ($v) => $v !== null);

        return new AppNotification($kind, $params, "ride:{$ride->id}", inList: $inList,
            channel: AppNotification::RIDES, tag: "ride-{$ride->id}");
    }

    private function toDocumentOwner(DriverDocument $document, string $kind, array $params, array $ref = []): void
    {
        $document->loadMissing(['requirement', 'driver.user']);
        $code = $document->requirement->code;
        $this->send($document->driver->user, new AppNotification(
            $kind,
            ['requirement' => $code, 'requirement_name' => $document->requirement->name] + $params,
            "requirement:{$code}",
            ref: $ref,
        ));
    }

    private function toSubscriber(Subscription $subscription, string $kind, array $params, array $ref = []): void
    {
        $this->send($subscription->loadMissing('user')->user, new AppNotification($kind, $params, 'subscription', ref: $ref));
    }

    /**
     * Every notification goes out here. It runs AFTER the change it reports was saved, so a
     * failure (database hiccup, anything) is reported for the developer and swallowed: a ride
     * step that succeeded must never answer the driver with an error (finish review #2).
     */
    private function send(?User $user, AppNotification $notification): void
    {
        try {
            $user?->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
