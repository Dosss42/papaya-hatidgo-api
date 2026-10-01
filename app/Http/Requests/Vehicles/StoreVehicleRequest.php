<?php

namespace App\Http\Requests\Vehicles;

use App\Support\PlateNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/vehicles: a driver registers their tricycle.
 * Messages: lang/{fil,en}/validation.php ('custom' + 'attributes'), in the request's language.
 */
class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:driver is checked by the route middleware
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::cleaned($this));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fieldRules(required: true);
    }

    /**
     * Shared with UpdateVehicleRequest.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(bool $required, ?int $ignoreVehicleId = null): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            // Letters and digits, 4–8 after removing spaces/dashes (motorcycle and tricycle plates).
            'plate_number' => [$presence, 'string', 'regex:/^[A-Z0-9]{4,8}$/',
                Rule::unique('vehicles', 'plate_number')->ignore($ignoreVehicleId)],
            // The number painted on the tricycle by the LGU/TODA; optional, unique in town.
            'body_number' => ['sometimes', 'nullable', 'string', 'max:20',
                Rule::unique('vehicles', 'body_number')->ignore($ignoreVehicleId)],
            'color' => [$presence, 'string', 'max:30'],
            'make' => ['sometimes', 'nullable', 'string', 'max:50'],
            'model' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Trim everything; plate in its one stored form; body number uppercase; empty text → null.
     *
     * @return array<string, mixed>
     */
    public static function cleaned(FormRequest $request): array
    {
        $out = [];
        if ($request->has('plate_number')) {
            $out['plate_number'] = PlateNumber::normalize($request->input('plate_number')) ?? '';
        }
        if ($request->has('body_number')) {
            $body = strtoupper(trim((string) $request->input('body_number')));
            $out['body_number'] = $body === '' ? null : $body;
        }
        foreach (['color', 'make', 'model'] as $field) {
            if ($request->has($field)) {
                $value = trim((string) $request->input($field));
                $out[$field] = $value === '' ? null : $value;
            }
        }

        return $out;
    }
}
