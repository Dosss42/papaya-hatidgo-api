<?php

namespace App\Models;

use App\Enums\EffectiveSubscriptionStatus;
use App\Enums\SubscriptionState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One paid period. Nothing is fillable: SubscriptionService creates subscriptions and
 * the verified payment webhook marks them paid.
 */
#[Fillable([])]
class Subscription extends Model
{
    protected function casts(): array
    {
        return [
            'state' => SubscriptionState::class,
            'amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The status users see, COMPUTED from stored facts + the clock (N18). Never stored,
     * so it cannot go stale. $graceDays comes from system_settings (subscription.grace_days).
     */
    public function effectiveStatus(int $graceDays = 0, ?CarbonInterface $now = null): EffectiveSubscriptionStatus
    {
        $now ??= now();

        return match ($this->state) {
            SubscriptionState::Pending => EffectiveSubscriptionStatus::Pending,
            SubscriptionState::Cancelled => EffectiveSubscriptionStatus::Cancelled,
            SubscriptionState::Suspended => EffectiveSubscriptionStatus::Suspended,
            SubscriptionState::Paid => match (true) {
                $now->lt($this->starts_at) => EffectiveSubscriptionStatus::Scheduled,
                $now->lt($this->ends_at) => EffectiveSubscriptionStatus::Active,
                $now->lt($this->ends_at->copy()->addDays($graceDays)) => EffectiveSubscriptionStatus::PastDue,
                default => EffectiveSubscriptionStatus::Expired,
            },
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class);
    }
}
