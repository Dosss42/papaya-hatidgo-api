<?php

namespace App\Http\Requests\Rides;

use App\Enums\RideType;
use App\Services\RideService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /rides. Only what the passenger CHOOSES is accepted; the fare, status and driver are set
 * by the server. `expected_total` is the total the passenger saw on the Mag-book button.
 */
class StoreRideRequest extends FormRequest
{
    use ValidatesTrip;

    public function authorize(): bool
    {
        return true; // role:passenger + subscribed are checked by the route middleware
    }

    protected function prepareForValidation(): void
    {
        foreach (['pickup_landmark', 'destination_landmark', 'passenger_note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) ?: null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->tripRules() + [
            'ride_type' => ['required', Rule::enum(RideType::class)],
            'wait_minutes' => ['nullable', 'integer', Rule::in(RideService::WAIT_CHOICES), 'prohibited_if:ride_type,one_way'],
            'pickup_landmark' => ['nullable', 'string', 'max:120'],
            'destination_landmark' => ['nullable', 'string', 'max:120'],
            'passenger_note' => ['nullable', 'string', 'max:200'],
            'expected_total' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
