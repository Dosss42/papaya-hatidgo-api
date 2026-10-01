<?php

namespace App\Payments;

/**
 * What the app needs from a payment provider (phase-0 § F.2, decision N4 "behind PaymentService").
 * SubscriptionService talks only to this interface, so the provider can change (or be the local
 * FakeGateway during development) without touching the subscription rules.
 *
 * Bound in AppServiceProvider from config('payments.gateway').
 */
interface PaymentGateway
{
    /** Create a hosted checkout page for one payment. */
    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /** Ask the provider directly what happened to a checkout (reconciliation, guarantee #5). */
    public function fetchCheckout(string $checkoutId): CheckoutStatus;

    /** Close a checkout so it can't be paid anymore (best effort: never throws). */
    public function expireCheckout(string $checkoutId): void;
}
