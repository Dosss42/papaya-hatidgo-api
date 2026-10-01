<?php

/*
 * English validation lines (locale "en"). Only OUR lines are here: Laravel merges this file on
 * top of its own English file, so standard rules ("The :attribute field is required.") keep working.
 * Taglish (the default): lang/fil/validation.php. Keep 'custom' and 'attributes' in sync with it.
 */

return [
    // Per-field messages (were hard-coded in each FormRequest's messages() until Phase 7).
    'custom' => [
        'email' => [
            'required' => 'Enter your email.',
            'email' => "That email isn't valid.",
            'unique' => 'An account already uses this email.',
        ],
        'phone' => [
            'regex' => 'Use a PH mobile number (e.g. 09171234567).',
            'unique' => 'An account already uses this number.',
        ],
        'password' => [
            'required' => 'Enter your password.',
            'confirmed' => "The passwords don't match.",
        ],
        'login' => [
            'required' => 'Enter your email or mobile number.',
        ],
        'role' => [
            'in' => 'Choose: Passenger or Driver.',
        ],
        'code' => [
            'required' => 'Enter the 6-digit code.',
            'digits' => 'The code is 6 digits.',
        ],
        'plate_number' => [
            'required' => 'Enter the plate number.',
            'regex' => 'Check the plate number (letters and digits, e.g. ABC 1234).',
            'unique' => 'A tricycle with this plate number is already registered.',
        ],
        'files' => [
            'required' => 'Upload a photo or PDF.',
            'max' => 'Up to 2 files only.',
        ],
        'files.*' => [
            'mimes' => 'Only JPG, PNG or PDF files are accepted.',
            'max' => 'Up to 5 MB per file.',
            'uploaded' => "The file didn't upload. It may be too large (up to 5 MB).",
        ],
        'expires_at' => [
            'required' => 'Enter the expiry date.',
            'after' => "The expiry date can't be in the past.",
        ],
        'reason' => [
            'required' => 'Enter the reason. The driver will read it.',
            'min' => 'The reason is too short.',
        ],
        'body_number' => [
            'unique' => 'A tricycle already uses this body number.',
        ],
    ],

    // Field names as the user sees them in the app (replaces :attribute).
    'attributes' => [
        'first_name' => 'first name',
        'last_name' => 'last name',
        'email' => 'email',
        'phone' => 'mobile number',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'account type',
        'login' => 'email or mobile number',
        'code' => 'code',
        'device_name' => 'device name',
        'plate_number' => 'plate number',
        'body_number' => 'body number',
        'color' => 'color',
        'make' => 'make',
        'model' => 'model',
        'requirement_id' => 'requirement',
        'reason' => 'reason',
        'files' => 'files',
        'expires_at' => 'expiry date',
        'issued_at' => 'issue date',
        'document_number' => 'document number',
    ],
];
