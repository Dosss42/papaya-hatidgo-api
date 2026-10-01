<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What the API reveals about a tricycle (to its own driver, and later to admins). */
class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate_number' => $this->plate_number,
            'body_number' => $this->body_number,
            'make' => $this->make,
            'model' => $this->model,
            'color' => $this->color,
            'status' => $this->status->value,
            // Derived, not stored on the vehicle: is this the driver's active tricycle?
            'is_active' => $this->driver?->active_vehicle_id === $this->id,
        ];
    }
}
