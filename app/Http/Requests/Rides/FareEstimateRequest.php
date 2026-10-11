<?php

namespace App\Http\Requests\Rides;

use App\Services\RideService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /fare/estimate: both ride types are priced at once (booking brief: "called once"). */
class FareEstimateRequest extends FormRequest
{
    use ValidatesTrip;

    public function authorize(): bool
    {
        return true; // role:passenger is checked by the route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->tripRules() + [
            // Balikan stay: 15 / 30 / 60 min, or empty = "Hindi pa sigurado" (open decision #1 default).
            'wait_minutes' => ['nullable', 'integer', Rule::in(RideService::WAIT_CHOICES)],
        ];
    }
}
