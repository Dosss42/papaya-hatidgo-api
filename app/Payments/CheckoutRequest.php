<?php

namespace App\Payments;

/** What we ask the provider to charge. Amounts are in CENTAVOS (₱199.00 = 19900), as PayMongo expects. */
final readonly class CheckoutRequest
{
    /**
     * @param  array<string, string>  $metadata  echoed back by the provider (e.g. our transaction id)
     */
    public function __construct(
        public string $reference,
        public string $description,
        public int $amountCentavos,
        public string $successUrl,
        public string $cancelUrl,
        public array $metadata = [],
    ) {}
}
