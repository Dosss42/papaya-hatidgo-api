<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A plan as the app shows it (Phase 8). The name is in the request's language. */
class SubscriptionPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->displayName(),
            'user_type' => $this->user_type->value,
            'duration_months' => $this->duration_months,
            'price' => $this->price,       // "199.00" (a string, so no float rounding on the way)
            'currency' => $this->currency,
            'benefits' => $this->benefits,
        ];
    }
}
