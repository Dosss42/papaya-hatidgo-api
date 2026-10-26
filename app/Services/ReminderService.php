<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\SubscriptionState;
use App\Models\DriverDocument;
use App\Models\Subscription;
use App\Support\BusinessDate;

/**
 * Time-based notifications (phase-0 § J #9, #12; Phase 13 decision #8), run by
 * `php artisan notifications:reminders` every hour from 7 AM to 8 PM Philippine time.
 *
 * Each reminder is sent ONCE: before sending, the notifications table is asked whether the same
 * kind about the same thing already went out. So the job can run any number of times, and a
 * missed hour (PC asleep, server restart) is simply caught by the next one.
 */
class ReminderService
{
    /** "Expires in 30 days" and "in 7 days" (§ J #9). */
    public const DOCUMENT_WINDOWS = [30, 7];

    /** "Your subscription ends in 3 days" (§ J #12). */
    public const SUBSCRIPTION_DAYS = 3;

    /** Expired things older than this are history, not news (no flood on the first run). */
    public const LOOK_BACK_DAYS = 7;

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return array{documents_expiring: int, documents_expired: int, subscriptions_ending: int, subscriptions_ended: int} */
    public function sendDue(): array
    {
        return [
            'documents_expiring' => $this->documentsExpiring(),
            'documents_expired' => $this->documentsExpired(),
            'subscriptions_ending' => $this->subscriptionsEnding(),
            'subscriptions_ended' => $this->subscriptionsEnded(),
        ];
    }

    /** Current, approved papers within 30 (or 7) days of their date, with no renewal sent yet. */
    private function documentsExpiring(): int
    {
        $today = BusinessDate::today();
        $sent = 0;

        DriverDocument::query()
            ->with(['requirement', 'driver.user'])
            ->where('status', DocumentStatus::Approved)
            ->where('is_current', true)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today->toDateString(), $today->addDays(max(self::DOCUMENT_WINDOWS))->toDateString()])
            ->each(function (DriverDocument $document) use (&$sent) {
                // The smallest window the date falls in: 12 days left → the 30-day reminder; 5 → the 7-day one.
                $days = BusinessDate::daysUntil($document->expires_at);
                $window = collect(self::DOCUMENT_WINDOWS)->filter(fn (int $w) => $days <= $w)->min();
                if ($this->renewalSent($document)
                    || $this->notifications->alreadySent($document->driver->user, 'document_expiring', ['document_id' => $document->id, 'window' => $window])) {
                    return;
                }
                $this->notifications->documentExpiring($document, $window);
                $sent++;
            });

        return $sent;
    }

    /** Current papers past their date in the last week (marked by the 00:05 job, or not yet). */
    private function documentsExpired(): int
    {
        $today = BusinessDate::today();
        $sent = 0;

        DriverDocument::query()
            ->with(['requirement', 'driver.user'])
            ->whereIn('status', [DocumentStatus::Approved, DocumentStatus::Expired])
            ->where('is_current', true)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today->subDays(self::LOOK_BACK_DAYS)->toDateString(), $today->subDay()->toDateString()])
            ->each(function (DriverDocument $document) use (&$sent) {
                if ($this->notifications->alreadySent($document->driver->user, 'document_expired', ['document_id' => $document->id])) {
                    return;
                }
                $this->notifications->documentExpired($document);
                $sent++;
            });

        return $sent;
    }

    /** A paid period ending within 3 days, with nothing paid after it. */
    private function subscriptionsEnding(): int
    {
        $sent = 0;

        Subscription::query()
            ->with('user')
            ->where('state', SubscriptionState::Paid)
            ->where('starts_at', '<=', now())
            ->whereBetween('ends_at', [now(), now()->addDays(self::SUBSCRIPTION_DAYS)])
            ->each(function (Subscription $subscription) use (&$sent) {
                if ($this->continued($subscription)
                    || $this->notifications->alreadySent($subscription->user, 'subscription_ending', ['subscription_id' => $subscription->id])) {
                    return;
                }
                $this->notifications->subscriptionEnding($subscription);
                $sent++;
            });

        return $sent;
    }

    /** A paid period that ended in the last week, with nothing paid after it. */
    private function subscriptionsEnded(): int
    {
        $sent = 0;

        Subscription::query()
            ->with('user')
            ->where('state', SubscriptionState::Paid)
            ->whereBetween('ends_at', [now()->subDays(self::LOOK_BACK_DAYS), now()])
            ->each(function (Subscription $subscription) use (&$sent) {
                if ($this->continued($subscription)
                    || $this->notifications->alreadySent($subscription->user, 'subscription_ended', ['subscription_id' => $subscription->id])) {
                    return;
                }
                $this->notifications->subscriptionEnded($subscription);
                $sent++;
            });

        return $sent;
    }

    /** The driver already uploaded the new paper (waiting for review): no need to remind. */
    private function renewalSent(DriverDocument $document): bool
    {
        return DriverDocument::query()
            ->where('driver_id', $document->driver_id)
            ->where('driver_requirement_id', $document->driver_requirement_id)
            ->where('status', DocumentStatus::Pending)
            ->where('is_current', false)
            ->exists();
    }

    /** Renewed: another paid period ends later than this one. */
    private function continued(Subscription $subscription): bool
    {
        return Subscription::query()
            ->where('user_id', $subscription->user_id)
            ->where('state', SubscriptionState::Paid)
            ->whereKeyNot($subscription->id)
            ->where('ends_at', '>', $subscription->ends_at)
            ->exists();
    }
}
