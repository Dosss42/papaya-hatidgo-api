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
    ],
];
