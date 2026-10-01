<?php

/*
 * Taglish validation messages (locale "fil"), for the rules the API actually uses.
 * Any rule NOT listed here falls back to Laravel's English file (fallback_locale = en).
 * Per-field messages in a FormRequest's messages() still win over these.
 * Style: plain everyday Taglish, one short sentence, tells the user what to do.
 */

return [
    'required' => 'Ilagay ang :attribute.',
    'string' => 'Hindi valid ang :attribute.',
    'email' => 'Hindi valid ang email.',
    'max' => [
        'string' => 'Masyadong mahaba ang :attribute (hanggang :max na character lang).',
    ],
    'min' => [
        'string' => 'Dapat :min o higit pang character ang :attribute.',
    ],
    'confirmed' => 'Hindi magkapareho ang :attribute.',
    'unique' => 'May account na gamit ang :attribute na ito.',
    'regex' => 'Hindi tama ang format ng :attribute.',
    'in' => 'Hindi valid ang napili sa :attribute.',
    'digits' => 'Dapat :digits na numero ang :attribute.',

    // Illuminate\Validation\Rules\Password
    'password' => [
        'letters' => 'Dapat may kahit isang letra ang :attribute.',
        'mixed' => 'Dapat may malaki at maliit na letra ang :attribute.',
        'numbers' => 'Dapat may kahit isang numero ang :attribute.',
        'symbols' => 'Dapat may kahit isang simbolo ang :attribute.',
        'uncompromised' => 'Na-leak na online ang :attribute na ito. Pumili ng iba.',
    ],

    // Per-field messages (were hard-coded in each FormRequest's messages() until Phase 7).
    // Keep in sync with lang/en/validation.php.
    'custom' => [
        'email' => [
            'required' => 'Ilagay ang email mo.',
            'email' => 'Hindi valid ang email.',
            'unique' => 'May account na gamit ang email na ito.',
        ],
        'phone' => [
            'regex' => 'Gamitin ang PH mobile number (hal. 09171234567).',
            'unique' => 'May account na gamit ang numerong ito.',
        ],
        'password' => [
            'required' => 'Ilagay ang password mo.',
            'confirmed' => 'Hindi magkapareho ang password.',
        ],
        'login' => [
            'required' => 'Ilagay ang email o mobile number mo.',
        ],
        'role' => [
            'in' => 'Pumili: Pasahero o Driver.',
        ],
        'code' => [
            'required' => 'Ilagay ang 6-digit code.',
            'digits' => 'Ang code ay 6 na numero.',
        ],
        'plate_number' => [
            'required' => 'Ilagay ang plate number.',
            'regex' => 'Tingnan ulit ang plate number (letra at numero, hal. ABC 1234).',
            'unique' => 'May nakarehistro nang tricycle na may plate number na ito.',
        ],
        'files' => [
            'required' => 'Mag-upload ng litrato o PDF.',
            'max' => 'Hanggang 2 file lang.',
        ],
        'files.*' => [
            'mimes' => 'JPG, PNG, o PDF lang ang tinatanggap.',
            'max' => 'Hanggang 5 MB lang bawat file.',
            'uploaded' => 'Hindi na-upload ang file. Baka masyadong malaki (hanggang 5 MB).',
        ],
        'expires_at' => [
            'required' => 'Ilagay ang expiry date.',
            'after' => 'Dapat hindi pa lumilipas ang expiry date.',
        ],
        'reason' => [
            'required' => 'Ilagay ang dahilan. Babasahin ito ng driver.',
            'min' => 'Masyadong maikli ang dahilan.',
        ],
        'body_number' => [
            'unique' => 'May tricycle nang gamit ang body number na ito.',
        ],
    ],

    // Field names as the user sees them in the app (replaces :attribute).
    'attributes' => [
        'first_name' => 'pangalan',
        'last_name' => 'apelyido',
        'email' => 'email',
        'phone' => 'mobile number',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'uri ng account',
        'login' => 'email o mobile number',
        'code' => 'code',
        'device_name' => 'pangalan ng device',
        'plate_number' => 'plate number',
        'body_number' => 'body number',
        'color' => 'kulay',
        'make' => 'brand',
        'model' => 'model',
        'requirement_id' => 'requirement',
        'reason' => 'dahilan',
        'files' => 'mga file',
        'expires_at' => 'expiry date',
        'issued_at' => 'petsa ng pagkakaissue',
        'document_number' => 'numero ng dokumento',
    ],
];
