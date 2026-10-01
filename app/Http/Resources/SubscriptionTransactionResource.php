<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment attempt, for the user's payment history (Phase 8). Never shows provider ids:
 * the user doesn't need them, and they're only useful to someone poking at the gateway.
 */
class SubscriptionTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'plan_name' => $this->subscription->plan->displayName(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'method' => $this->payment_method,
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
