<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/auth/login (contract: phase-5-authentication.md § 3.2). */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint (rate-limited by the 'login' limiter)
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'], // email OR phone
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Ilagay ang email o mobile number mo.',
            'password.required' => 'Ilagay ang password mo.',
        ];
    }
}
