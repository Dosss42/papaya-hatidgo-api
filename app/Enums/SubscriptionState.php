<?php

namespace App\Enums;

/**
 * Mirrors subscriptions.state: facts that do not change with the calendar.
 * Active / past_due / expired are COMPUTED from the dates (see EffectiveSubscriptionStatus).
 */
enum SubscriptionState: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';
}
