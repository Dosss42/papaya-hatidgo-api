<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A requirement definition, named in the request's language. */
class RequirementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->displayName(),
            'description' => $this->displayDescription(),
            'applies_to' => $this->applies_to->value, // driver | vehicle
            'max_files' => $this->max_files,          // 2 = front + back
            'requires_expiry' => $this->requires_expiry,
            'is_critical' => $this->is_critical,
        ];
    }
}
