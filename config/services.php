<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Subscription payments (Phase 8). Keys only in .env: the secret key creates checkouts,
    // the webhook secret proves a webhook really came from PayMongo. See config/payments.php.
    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
    ],

    // Push notifications (Phase 13): the PATH to the Firebase service-account JSON file, kept
    // outside the repository. Empty = pushes are skipped (the in-app list still works).
    'fcm' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // Road routing (after Phase 13): OpenRouteService measures the trip along the roads for the
    // fare. Key only in .env. Without it (or when the service is down), the straight line × the
    // fallback factor is used (RoutingService::FALLBACK).
    'routing' => [
        'ors_key' => env('OPENROUTESERVICE_KEY'),
        'fallback_factor' => (float) env('ROUTING_FALLBACK_FACTOR', 1.3),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
