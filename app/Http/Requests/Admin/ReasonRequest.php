<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin actions that must say WHY: reject, request resubmission, suspend.
 * The driver reads this reason in the app, so it's required (the brief's anti-goal:
 * "Rejected with no reason"). The database enforces it too (chk_review_reason).
 */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:admin is checked by the route middleware
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }
}
