<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/v1/auth/register (contract: phase-5-authentication.md § 3.1).
 * Runs BEFORE the controller: if validation fails, the controller never executes.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint (rate-limited by the 'register' limiter)
    }

    /** Clean the input first, so "unique" compares like with like. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            // If it can't be normalized, keep what was typed so the regex rule reports it.
            'phone' => PhoneNumber::normalize($this->input('phone')) ?? $this->input('phone'),
            'first_name' => trim((string) $this->input('first_name')),
            'last_name' => trim((string) $this->input('last_name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\+639\d{9}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            // Only these two. An admin account can never be created through the public API.
            'role' => ['required', 'in:passenger,driver'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'May account na gamit ang email na ito.',
            'email.email' => 'Hindi valid ang email.',
            'phone.regex' => 'Gamitin ang PH mobile number (hal. 09171234567).',
            'phone.unique' => 'May account na gamit ang numerong ito.',
            'password.confirmed' => 'Hindi magkapareho ang password.',
            'role.in' => 'Pumili: Pasahero o Driver.',
        ];
    }
}
