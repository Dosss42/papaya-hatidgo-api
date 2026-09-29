<?php

namespace App\Enums;

/**
 * The status users and the gates see, computed by SubscriptionService from
 * subscriptions.state + starts_at / ends_at + subscription.grace_days. Never stored.
 */
enum EffectiveSubscriptionStatus: string
{
    case Pending = 'pending';     // checkout created, not paid
    case Scheduled = 'scheduled'; // paid, period starts later (renewed early)
    case Active = 'active';       // paid, starts_at <= now < ends_at
    case PastDue = 'past_due';    // ended, still within grace days
    case Expired = 'expired';     // ended, past grace
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';
}
