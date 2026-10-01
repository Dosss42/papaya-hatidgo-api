<?php

namespace App\Payments;

/**
 * What the provider says about a checkout, from the webhook or from asking it directly.
 * SubscriptionService decides what that means; this class only carries the facts.
 */
final readonly class CheckoutStatus
{
    public function __construct(
        public bool $paid,
        public bool $expired = false,
        public ?string $paymentId = null,
        public ?string $method = null,        // gcash, paymaya, card…
        public ?int $amountCentavos = null,   // what was actually paid
    ) {}

    public static function unpaid(bool $expired = false): self
    {
        return new self(paid: false, expired: $expired);
    }
}
