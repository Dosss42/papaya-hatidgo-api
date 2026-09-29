<?php

namespace App\Services;

use App\Enums\EffectiveSubscriptionStatus;
use App\Enums\SubscriptionState;
use App\Models\Subscription;
use App\Models\User;

/**
 * The single place that decides a user's subscription status (phase-0 § F).
 * Phase 5 needs only the read side (for /auth/me); checkout and activation come in Phase 8.
 */
class SubscriptionService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** The paid period that matters most right now: the one ending last among those already started. */
    public function current(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->where('state', SubscriptionState::Paid)
            ->where('starts_at', '<=', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    /** Computed, never stored (N18): active / past_due / expired from dates + grace days. */
    public function status(User $user): ?EffectiveSubscriptionStatus
    {
        $graceDays = (int) $this->settings->get('subscription.grace_days', 0);

        return $this->current($user)?->effectiveStatus($graceDays);
    }

    /** The booking and go-online gates use this. */
    public function isActive(User $user): bool
    {
        return $this->status($user) === EffectiveSubscriptionStatus::Active;
    }
}
