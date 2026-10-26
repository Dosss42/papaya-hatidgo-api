<?php

/*
 * Notification texts in English (Phase 13). One entry per kind (App\Notifications\AppNotification);
 * Taglish: lang/fil/notifications.php, same keys. `body_reason` is used instead of `body` when the
 * notification carries a reason. Short on purpose: Android shows about two lines.
 */

return [
    // Rides (push only, decision #4)
    'ride_offer' => [
        'title' => 'New ride request · ₱:fare',
        'body' => 'Pickup: :pickup. Open the app to accept.',
    ],
    'ride_accepted' => [
        'title' => 'A driver accepted your ride',
        'body' => ':driver is getting ready. Tricycle :plate.',
    ],
    'ride_arriving' => [
        'title' => 'Your driver is on the way',
        'body' => ':driver is coming to your pickup.',
    ],
    'ride_arrived' => [
        'title' => 'Your driver is here',
        'body' => 'Look for tricycle :plate (:color).',
    ],
    'passenger_returning' => [
        'title' => 'The passenger is coming back',
        'body' => 'Get ready for the trip back.',
    ],

    // Rides (Alerts list + push)
    'ride_completed' => [
        'title' => 'Ride completed',
        'body' => 'Total: ₱:total, paid in cash. Thank you for riding!',
    ],
    'ride_no_driver' => [
        'title' => 'No driver found',
        'body' => 'No driver accepted your request in time. You were not charged. You can book again.',
    ],
    'ride_cancelled_by_driver' => [
        'title' => 'Your ride was cancelled',
        'body' => 'The driver cancelled the ride. You were not charged.',
        'body_reason' => 'The driver cancelled the ride: :reason. You were not charged.',
    ],
    'ride_cancelled_by_passenger' => [
        'title' => 'The passenger cancelled',
        'body' => 'The ride was cancelled. You can take other requests.',
        'body_reason' => 'The ride was cancelled: :reason. You can take other requests.',
    ],
    'ride_cancelled_by_admin' => [
        'title' => 'Your ride was cancelled',
        'body' => 'Papaya HatidGo cancelled the ride. You were not charged.',
        'body_reason' => 'Papaya HatidGo cancelled the ride: :reason',
    ],

    // Driver papers and work
    'document_approved' => [
        'title' => 'Paper approved',
        'body' => 'Your :requirement is approved.',
    ],
    'document_rejected' => [
        'title' => 'Paper rejected',
        'body' => 'Your :requirement was rejected.',
        'body_reason' => 'Your :requirement was rejected: :reason. Please upload it again.',
    ],
    'document_resubmission' => [
        'title' => 'New photo needed',
        'body' => 'Please send a new photo of your :requirement.',
        'body_reason' => 'Please send a new photo of your :requirement: :reason',
    ],
    'document_expiring' => [
        'title' => 'A paper expires soon',
        'body' => 'Your :requirement expires on :date. Upload the new one so you can keep driving.',
    ],
    'document_expired' => [
        'title' => 'A paper expired',
        'body' => "Your :requirement expired. You can't go online until the new one is approved.",
    ],
    'driver_offline_stale' => [
        'title' => 'You were set offline',
        'body' => 'Your phone stopped sending its location for 3 minutes. Open the app to go online again.',
    ],
    'driver_offline_papers' => [
        'title' => 'You were set offline',
        'body' => 'Your papers need attention. Open your requirements to see what to fix.',
    ],

    // Subscriptions
    'subscription_activated' => [
        'title' => 'Subscription active',
        'body' => "You're subscribed until :date.",
    ],
    'subscription_ending' => [
        'title' => 'Your subscription ends soon',
        'body' => 'It ends on :date. Renew early: no days are lost.',
    ],
    'subscription_ended' => [
        'title' => 'Your subscription ended',
        'body' => 'It ended on :date. Renew to keep using Papaya HatidGo.',
    ],
    'payment_expired' => [
        'title' => 'Payment not completed',
        'body' => 'The payment page for ₱:amount expired before it was paid. Nothing was charged.',
    ],
    'payment_rejected' => [
        'title' => 'Payment problem',
        'body' => "The amount paid didn't match ₱:amount, so no subscription was added. Contact Papaya HatidGo.",
    ],

    // Cancel reasons the app sends as codes (free text stays as typed)
    'reasons' => [
        'driver_late' => 'the driver was taking long',
        'changed_plans' => 'plans changed',
        'wrong_pickup' => 'wrong pickup',
        'vehicle_problem' => 'tricycle problem',
        'passenger_not_found' => "couldn't find the passenger",
        'passenger_no_show' => "the passenger didn't show up",
        'other' => 'other reason',
    ],

    'pickup_unnamed' => 'near you',
];
