<?php

/*
 * Subscription payments (Phase 8, phase-0 § F.2). The PayMongo keys themselves are in
 * config/services.php (read from .env, never committed).
 */

return [
    // fake = our local test checkout page (development only, refused in production)
    // paymongo = real PayMongo Checkout (use TEST keys sk_test_… until the client goes live)
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    // What PayMongo's page offers (PayMongo names: gcash, paymaya, card, grab_pay, …).
    'methods' => array_values(array_filter(explode(',', (string) env('PAYMONGO_METHODS', 'gcash,paymaya,card')))),

    // The app's deep link back from the payment page (AndroidManifest intent-filter, Phase 8.5).
    'app_return_url' => env('PAYMENT_APP_RETURN_URL', 'com.papayahatidgo.app://payment'),

    // How long a pending checkout may still be reconciled with the gateway (PayMongo checkouts
    // expire after about a day; older pending ones are simply left unpaid).
    'reconcile_within_hours' => 24,
];
