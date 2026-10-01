<?php

namespace App\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A stand-in for PayMongo during development (Phase 8 decision #1): its "checkout page" is our own
 * test page (/payments/fake-checkout/{id}, FakeCheckoutController) with Pay and Cancel buttons.
 *
 * Everything AFTER the page is the real code: the app opens the page in a Custom Tab, comes back
 * through /payments/return, and the server confirms the payment by asking the gateway
 * (fetchCheckout = reconciliation) before SubscriptionService activates anything.
 *
 * Checkouts live in the cache for a day. AppServiceProvider refuses this class in production.
 */
class FakeGateway implements PaymentGateway
{
    private const TTL_HOURS = 24;

    public static function cacheKey(string $checkoutId): string
    {
        return 'fake-checkout:'.$checkoutId;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $id = 'fake_cs_'.Str::random(24);

        Cache::put(self::cacheKey($id), [
            'status' => 'active',
            'description' => $request->description,
            'amount' => $request->amountCentavos,
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
        ], now()->addHours(self::TTL_HOURS));

        return new CheckoutSession($id, route('payments.fake.show', ['checkout' => $id]));
    }

    public function fetchCheckout(string $checkoutId): CheckoutStatus
    {
        $checkout = Cache::get(self::cacheKey($checkoutId));

        return match ($checkout['status'] ?? 'expired') {
            'paid' => new CheckoutStatus(
                paid: true,
                paymentId: $checkout['payment_id'],
                method: $checkout['method'],
                amountCentavos: $checkout['amount'],
            ),
            'active' => CheckoutStatus::unpaid(),
            default => CheckoutStatus::unpaid(expired: true),
        };
    }

    public function expireCheckout(string $checkoutId): void
    {
        $checkout = Cache::get(self::cacheKey($checkoutId));
        if ($checkout && $checkout['status'] === 'active') {
            Cache::put(self::cacheKey($checkoutId), ['status' => 'expired'] + $checkout, now()->addHours(self::TTL_HOURS));
        }
    }

    /** The test page's "Pay" button (FakeCheckoutController). */
    public static function markPaid(string $checkoutId, string $method): void
    {
        $checkout = Cache::get(self::cacheKey($checkoutId));
        if ($checkout && $checkout['status'] === 'active') {
            Cache::put(self::cacheKey($checkoutId), [
                'status' => 'paid',
                'payment_id' => 'fake_pay_'.Str::random(24),
                'method' => $method,
            ] + $checkout, now()->addHours(self::TTL_HOURS));
        }
    }
}
