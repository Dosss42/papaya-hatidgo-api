<?php

namespace App\Http\Requests\Vehicles;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/vehicles/{id}: change any of the tricycle's details (only the fields sent).
 * Changing the plate number triggers a re-review (VehicleService::update).
 */
class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked in the controller (another driver's tricycle = 404)
    }

    protected function prepareForValidation(): void
    {
        $this->merge(StoreVehicleRequest::cleaned($this));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // The vehicle being edited may keep its own plate/body number (unique "except itself").
        return StoreVehicleRequest::fieldRules(required: false, ignoreVehicleId: (int) $this->route('vehicle'));
    }
}
