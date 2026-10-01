<?php

namespace App\Http\Resources;

use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One subscription period (Phase 8). `status` is the COMPUTED status (active / expired / …,
 * decision N18), never a stored one. `payment` is the newest checkout attempt.
 * Load with `plan` and `transactions` (strict mode refuses lazy loading in lists).
 */
class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->transactions->sortByDesc('id')->first();

        return [
            'id' => $this->id,
            'status' => app(SubscriptionService::class)->statusOf($this->resource)->value,
            'plan' => new SubscriptionPlanResource($this->plan),
            'amount' => $this->amount,
            'starts_at' => $this->starts_at?->toIso8601ZuluString(),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'payment' => $payment ? [
                'status' => $payment->status->value,
                'method' => $payment->payment_method,
                'paid_at' => $payment->paid_at?->toIso8601ZuluString(),
            ] : null,
        ];
    }
}
