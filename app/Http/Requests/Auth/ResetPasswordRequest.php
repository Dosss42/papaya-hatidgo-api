<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** POST /api/v1/auth/reset-password (contract: phase-5-authentication.md § 3.6). */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint (rate-limited by 'reset-password': 5 per 15 min per email)
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'code' => preg_replace('/\D+/', '', (string) $this->input('code')), // "123 456" → "123456"
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    // Messages: lang/{fil,en}/validation.php ('custom' + 'attributes'), in the request's language.
}
