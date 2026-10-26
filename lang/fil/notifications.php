<?php

/*
 * Notification texts in Taglish (Phase 13). Same keys as lang/en/notifications.php.
 * Glossary and tone: the app's messages.fil.ts.
 */

return [
    // Rides (push only, decision #4)
    'ride_offer' => [
        'title' => 'May bagong request · ₱:fare',
        'body' => 'Pickup: :pickup. Buksan ang app para tanggapin.',
    ],
    'ride_accepted' => [
        'title' => 'May driver na ang biyahe mo',
        'body' => 'Naghahanda na si :driver. Tricycle :plate.',
    ],
    'ride_arriving' => [
        'title' => 'Papunta na ang driver mo',
        'body' => 'Papunta na si :driver sa pickup mo.',
    ],
    'ride_arrived' => [
        'title' => 'Nandito na ang driver mo',
        'body' => 'Hanapin ang tricycle :plate (:color).',
    ],
    'passenger_returning' => [
        'title' => 'Pabalik na ang pasahero',
        'body' => 'Maghanda para sa biyaheng pabalik.',
    ],

    // Rides (Alerts list + push)
    'ride_completed' => [
        'title' => 'Tapos na ang biyahe',
        'body' => 'Kabuuan: ₱:total, cash. Salamat sa pagsakay!',
    ],
    'ride_no_driver' => [
        'title' => 'Walang nakuhang driver',
        'body' => 'Walang driver na tumanggap sa oras. Hindi ka sinisingil. Puwede kang mag-book ulit.',
    ],
    'ride_cancelled_by_driver' => [
        'title' => 'Na-cancel ang biyahe mo',
        'body' => 'Kinansela ng driver ang biyahe. Hindi ka sinisingil.',
        'body_reason' => 'Kinansela ng driver ang biyahe: :reason. Hindi ka sinisingil.',
    ],
    'ride_cancelled_by_passenger' => [
        'title' => 'Nag-cancel ang pasahero',
        'body' => 'Na-cancel ang biyahe. Puwede kang tumanggap ng ibang request.',
        'body_reason' => 'Na-cancel ang biyahe: :reason. Puwede kang tumanggap ng ibang request.',
    ],
    'ride_cancelled_by_admin' => [
        'title' => 'Na-cancel ang biyahe mo',
        'body' => 'Kinansela ng Papaya HatidGo ang biyahe. Hindi ka sinisingil.',
        'body_reason' => 'Kinansela ng Papaya HatidGo ang biyahe: :reason',
    ],

    // Driver papers and work
    'document_approved' => [
        'title' => 'Aprubado ang papel',
        'body' => 'Aprubado na ang :requirement mo.',
    ],
    'document_rejected' => [
        'title' => 'Hindi tinanggap ang papel',
        'body' => 'Hindi tinanggap ang :requirement mo.',
        'body_reason' => 'Hindi tinanggap ang :requirement mo: :reason. Paki-upload ulit.',
    ],
    'document_resubmission' => [
        'title' => 'Kailangan ng bagong litrato',
        'body' => 'Magpadala ng bagong litrato ng :requirement mo.',
        'body_reason' => 'Magpadala ng bagong litrato ng :requirement mo: :reason',
    ],
    'document_expiring' => [
        'title' => 'Malapit nang mag-expire ang papel',
        'body' => 'Mag-e-expire ang :requirement mo sa :date. I-upload ang bago para tuloy ang pamamasada.',
    ],
    'document_expired' => [
        'title' => 'Nag-expire ang papel',
        'body' => 'Nag-expire ang :requirement mo. Hindi ka makakapag-online hangga’t hindi naaaprubahan ang bago.',
    ],
    'driver_offline_stale' => [
        'title' => 'Na-offline ka',
        'body' => '3 minutong hindi nagpadala ng lokasyon ang phone mo. Buksan ang app para mag-online ulit.',
    ],
    'driver_offline_papers' => [
        'title' => 'Na-offline ka',
        'body' => 'May kailangang ayusin sa mga papel mo. Buksan ang requirements para makita.',
    ],

    // Subscriptions
    'subscription_activated' => [
        'title' => 'Active na ang subscription',
        'body' => 'Subscribed ka hanggang :date.',
    ],
    'subscription_ending' => [
        'title' => 'Malapit nang matapos ang subscription mo',
        'body' => 'Matatapos ito sa :date. Mag-renew nang maaga: walang masasayang na araw.',
    ],
    'subscription_ended' => [
        'title' => 'Natapos ang subscription mo',
        'body' => 'Natapos ito noong :date. Mag-renew para tuloy-tuloy sa Papaya HatidGo.',
    ],
    'payment_expired' => [
        'title' => 'Hindi natuloy ang bayad',
        'body' => 'Nag-expire ang payment page para sa ₱:amount bago nabayaran. Walang nabawas.',
    ],
    'payment_rejected' => [
        'title' => 'May problema sa bayad',
        'body' => 'Hindi tugma sa ₱:amount ang binayaran, kaya walang subscription na naidagdag. Kontakin ang Papaya HatidGo.',
    ],

    // Cancel reasons the app sends as codes (free text stays as typed)
    'reasons' => [
        'driver_late' => 'matagal ang driver',
        'changed_plans' => 'nagbago ang plano',
        'wrong_pickup' => 'mali ang pickup',
        'vehicle_problem' => 'nasiraan ang tricycle',
        'passenger_not_found' => 'hindi mahanap ang pasahero',
        'passenger_no_show' => 'hindi dumating ang pasahero',
        'other' => 'ibang dahilan',
    ],

    'pickup_unnamed' => "malapit sa'yo",
];
