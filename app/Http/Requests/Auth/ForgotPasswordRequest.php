<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/auth/forgot-password (contract: phase-5-authentication.md § 3.5). */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint (rate-limited by 'forgot-password')
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            // Deliberately NOT "exists:users,email": that would reveal which emails have accounts.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Ilagay ang email mo.',
            'email.email' => 'Hindi valid ang email.',
        ];
    }
}
