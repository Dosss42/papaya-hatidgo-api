<?php

namespace App\Payments;

/** A created checkout: the provider's id (saved as provider_checkout_id) and the page the user opens. */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {}
}
