<?php

namespace App\Services;

use App\Enums\EffectiveSubscriptionStatus;
use App\Enums\SubscriptionState;
use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Payments\CheckoutRequest;
use App\Payments\CheckoutStatus;
use App\Payments\PaymentGateway;
use App\Support\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place that decides a user's subscription status (phase-0 § F), and the ONLY code
 * that marks a payment as received (activate(), guarantee #1). Its callers: the verified
 * webhook, reconciliation (asking the gateway), and the admin's audited manual fallback.
 */
class SubscriptionService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PaymentGateway $gateway,
        private readonly AuditService $audit,
    ) {}

    // ---------------------------------------------------------------- read side (Phase 5)

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
        $current = $this->current($user);

        return $current ? $this->statusOf($current) : null;
    }

    public function statusOf(Subscription $subscription): EffectiveSubscriptionStatus
    {
        return $subscription->effectiveStatus((int) $this->settings->get('subscription.grace_days', 0));
    }

    /** The booking and go-online gates use this. */
    public function isActive(User $user): bool
    {
        return $this->status($user) === EffectiveSubscriptionStatus::Active;
    }

    /** A paid period that starts later (renewed early). */
    public function renewal(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->where('state', SubscriptionState::Paid)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /** The newest checkout still waiting for its payment, while it can still be paid. */
    public function pending(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->where('state', SubscriptionState::Pending)
            ->where('created_at', '>=', now()->subHours((int) config('payments.reconcile_within_hours')))
            ->latest('id')
            ->first();
    }

    /** The plans this user may buy: their role's active plans, in the admin's order. */
    public function plansFor(User $user): Collection
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->where('user_type', $user->role->value)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Everything GET /subscriptions/current shows. A checkout still waiting is checked with the
     * gateway first (guarantee #5), so a late webhook never leaves the user stuck.
     *
     * @return array{status: string, current: ?Subscription, renewal: ?Subscription, pending: ?Subscription, active_until: ?CarbonInterface, remaining_days: int}
     */
    public function summary(User $user): array
    {
        if ($pending = $this->pending($user)) {
            $this->reconcile($pending);
        }

        $current = $this->current($user);
        $status = $current ? $this->statusOf($current) : null;
        $renewal = $this->renewal($user);
        $activeUntil = $renewal?->ends_at ?? ($status === EffectiveSubscriptionStatus::Active ? $current->ends_at : null);

        return [
            'status' => $status->value ?? 'none',
            'current' => $current,
            'renewal' => $renewal,
            'pending' => $this->pending($user),
            'active_until' => $activeUntil,
            // Days left, renewal included, rounded up ("1 day left" until the very end).
            'remaining_days' => $activeUntil ? (int) max(0, ceil(now()->diffInSeconds($activeUntil, false) / 86400)) : 0,
        ];
    }

    // ---------------------------------------------------------------- checkout

    /**
     * POST /subscriptions: a pending subscription + its checkout at the gateway. The price is
     * copied from the plan now (amount), so a later price change can't alter this purchase.
     *
     * @return array{subscription: Subscription, checkout_url: string}
     */
    public function startCheckout(User $user, SubscriptionPlan $plan): array
    {
        if (! $plan->is_active) {
            throw new ApiException(__('api.plan_inactive'), 'PLAN_INACTIVE', 422);
        }
        if ($plan->user_type->value !== $user->role->value) {
            throw new ApiException(__('api.plan_not_for_you'), 'PLAN_NOT_FOR_YOU', 422);
        }

        // An unpaid checkout is replaced, but only after asking the gateway: it may have been
        // paid a moment ago (decision #5, "a payment always wins").
        foreach ($user->subscriptions()->where('state', SubscriptionState::Pending)->get() as $old) {
            if ($this->reconcile($old)->state === SubscriptionState::Pending) {
                $this->closeUnpaid($old, 'replaced_by_new_checkout');
            }
        }

        // At most one renewal waiting (decision #4): no paying for the same months twice by mistake.
        if ($renewal = $this->renewal($user)) {
            throw new ApiException(
                __('api.already_renewed', ['date' => BusinessDate::format($renewal->starts_at)]),
                'ALREADY_RENEWED',
                409,
            );
        }

        $subscription = Subscription::forceCreate([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'state' => SubscriptionState::Pending,
            'amount' => $plan->price,
        ]);

        // The gateway call happens OUTSIDE any database transaction (a slow network must not hold
        // database locks). If it fails, the half-made subscription is removed again.
        try {
            $locale = app()->getLocale();
            $session = $this->gateway->createCheckout(new CheckoutRequest(
                reference: 'PHG-SUB-'.$subscription->id,
                description: $plan->displayName(),
                amountCentavos: self::centavos($subscription->amount),
                successUrl: route('payments.return', ['result' => 'success', 'lang' => $locale]),
                cancelUrl: route('payments.return', ['result' => 'cancelled', 'lang' => $locale]),
                metadata: ['subscription_id' => (string) $subscription->id, 'user_id' => (string) $user->id],
            ));
        } catch (Throwable $e) {
            $subscription->delete();
            throw $e;
        }

        SubscriptionTransaction::forceCreate([
            'subscription_id' => $subscription->id,
            'amount' => $subscription->amount,
            'currency' => $plan->currency,
            'status' => TransactionStatus::Pending,
            'provider' => config('payments.gateway'), // paymongo, or "fake" for local test payments
            'provider_checkout_id' => $session->id,
        ]);

        return ['subscription' => $subscription->refresh(), 'checkout_url' => $session->url];
    }

    /**
     * Reconciliation (guarantee #5): ask the gateway what happened to a pending checkout.
     * Paid → activate. Expired → closed. Gateway unreachable → unchanged (asked again later).
     */
    public function reconcile(Subscription $subscription): Subscription
    {
        if ($subscription->state !== SubscriptionState::Pending) {
            return $subscription;
        }

        $transaction = $subscription->transactions()->where('status', TransactionStatus::Pending)->latest('id')->first();
        if (! $transaction) {
            return $subscription;
        }

        try {
            $result = $this->gateway->fetchCheckout($transaction->provider_checkout_id);
        } catch (ApiException) {
            Log::info('Reconciliation postponed: gateway unavailable', ['subscription_id' => $subscription->id]);

            return $subscription;
        }

        if ($result->paid) {
            return $this->activate($transaction, $result, 'reconciliation');
        }
        if ($result->expired) {
            $this->closeUnpaid($subscription, 'checkout_expired', alreadyExpired: true);
        }

        return $subscription->refresh();
    }

    /** POST /subscriptions/{id}/cancel: the user backed out of a checkout they haven't paid. */
    public function cancel(Subscription $subscription): Subscription
    {
        if ($subscription->state !== SubscriptionState::Pending) {
            throw new ApiException(__('api.subscription_not_pending'), 'SUBSCRIPTION_NOT_PENDING', 409);
        }

        // Paid after all (paid, then pressed Cancel)? Then the payment wins.
        if ($this->reconcile($subscription)->state === SubscriptionState::Pending) {
            $this->closeUnpaid($subscription, 'cancelled_by_user');
        }

        return $subscription->refresh();
    }

    // ---------------------------------------------------------------- activation

    /**
     * THE place where money becomes a subscription (guarantee #1). Idempotent (guarantee #2): a
     * transaction already paid is left alone, so a repeated webhook, a webhook racing a
     * reconciliation, or a double tap all end in ONE paid period.
     *
     * @param  string  $via  webhook | reconciliation | manual (recorded in the audit log)
     */
    public function activate(
        SubscriptionTransaction $transaction,
        CheckoutStatus $payment,
        string $via,
        ?User $actor = null,
        ?string $reason = null,
    ): Subscription {
        return DB::transaction(function () use ($transaction, $payment, $via, $actor, $reason) {
            // Row locks: two deliveries at the same moment wait for each other here.
            $tx = SubscriptionTransaction::lockForUpdate()->findOrFail($transaction->id);
            $subscription = Subscription::with('plan')->lockForUpdate()->findOrFail($tx->subscription_id);

            if ($tx->status === TransactionStatus::Paid) {
                return $subscription; // already done: nothing changes the second time
            }

            // Guarantee #3: the amount actually paid must be exactly what we asked for.
            $expected = self::centavos($tx->amount);
            if ($payment->amountCentavos !== $expected) {
                $tx->forceFill([
                    'status' => TransactionStatus::Failed,
                    'failure_reason' => "amount_mismatch: paid {$payment->amountCentavos}, expected {$expected}",
                ])->save();
                $this->audit->record($actor, 'subscription.payment_rejected', $subscription, null, [
                    'transaction_id' => $tx->id, 'paid_centavos' => $payment->amountCentavos,
                    'expected_centavos' => $expected, 'via' => $via,
                ]);
                Log::warning('Payment amount mismatch', ['transaction_id' => $tx->id]);

                return $subscription;
            }

            // Renewal timing (§ F.1): start where the paid time already ends, so no paid day is lost.
            $paidUntil = Subscription::query()
                ->where('user_id', $subscription->user_id)
                ->where('state', SubscriptionState::Paid)
                ->where('ends_at', '>', now())
                ->whereKeyNot($subscription->id)
                ->max('ends_at');
            $startsAt = $paidUntil ? Carbon::parse($paidUntil)->max(now()) : now();

            $subscription->forceFill([
                'state' => SubscriptionState::Paid,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMonthsNoOverflow($subscription->plan->duration_months),
                'cancelled_at' => null, // money for a cancelled checkout still counts (decision #5)
            ])->save();

            $tx->forceFill([
                'status' => TransactionStatus::Paid,
                'provider_payment_id' => $payment->paymentId,
                'payment_method' => $payment->method,
                'paid_at' => now(),
                'failure_reason' => null,
            ])->save();

            $this->audit->record($actor, 'subscription.activated', $subscription, null, array_filter([
                'transaction_id' => $tx->id,
                'via' => $via,
                'starts_at' => $subscription->starts_at->toIso8601ZuluString(),
                'ends_at' => $subscription->ends_at->toIso8601ZuluString(),
                'reason' => $reason,
            ]));
            // "Your subscription is active" notification: Phase 13.

            return $subscription;
        });
    }

    /** Demo fallback (guarantee #6): an admin activates an unpaid subscription by hand, with a reason. */
    public function activateManually(User $admin, Subscription $subscription, string $reason): Subscription
    {
        if (! in_array($subscription->state, [SubscriptionState::Pending, SubscriptionState::Cancelled], true)) {
            throw new ApiException(__('api.subscription_not_activatable'), 'SUBSCRIPTION_NOT_ACTIVATABLE', 409);
        }

        $transaction = $subscription->transactions()->latest('id')->first()
            ?? SubscriptionTransaction::forceCreate([
                'subscription_id' => $subscription->id,
                'amount' => $subscription->amount,
                'status' => TransactionStatus::Pending,
                'provider' => 'manual',
                'provider_checkout_id' => 'manual_'.$subscription->id.'_'.now()->timestamp,
            ]);

        return $this->activate(
            $transaction,
            new CheckoutStatus(paid: true, method: 'manual', amountCentavos: self::centavos($transaction->amount)),
            'manual',
            $admin,
            $reason,
        );
    }

    // ---------------------------------------------------------------- helpers

    /** ₱199.00 → 19900 (what PayMongo expects). Rounded, so float noise can't change a centavo. */
    public static function centavos(string|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** An unpaid checkout that will never be paid: subscription cancelled, transaction closed, gateway told. */
    private function closeUnpaid(Subscription $subscription, string $reason, bool $alreadyExpired = false): void
    {
        DB::transaction(function () use ($subscription, $reason, $alreadyExpired) {
            foreach ($subscription->transactions()->where('status', TransactionStatus::Pending)->get() as $tx) {
                if (! $alreadyExpired) {
                    $this->gateway->expireCheckout($tx->provider_checkout_id);
                }
                $tx->forceFill([
                    'status' => $alreadyExpired ? TransactionStatus::Expired : TransactionStatus::Failed,
                    'failure_reason' => $reason,
                ])->save();
            }

            $subscription->forceFill(['state' => SubscriptionState::Cancelled, 'cancelled_at' => now()])->save();
        });
    }
}
