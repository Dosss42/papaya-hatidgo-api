<?php

/*
 * API messages in English (locale "en"). Taglish, the default: lang/fil/api.php.
 * Every key here MUST also exist in lang/fil/api.php.
 */

return [
    // Error format (ApiErrorRenderer)
    'validation_failed' => 'Some of what you entered needs fixing. Please check the fields.',
    'forbidden' => "You're not allowed to do this.",
    'not_found' => "We couldn't find what you're looking for.",
    'too_many_attempts' => 'Too many attempts. Please wait before trying again.',
    'method_not_allowed' => "This request isn't supported.",
    'http_error' => 'Something is wrong with the request.',
    'server_error' => 'Something went wrong on the server. Please try again later.',

    // Auth
    'forbidden_role' => "This part isn't available for your account.",
    'invalid_credentials' => 'Wrong email/mobile number or password.',
    'account_suspended' => 'Your account is suspended. Please contact the Papaya HatidGo admin.',

    // Password reset
    'reset_code_sent' => "If this email has an account, we've sent a 6-digit code. It's valid for :minutes minutes.",
    'password_changed' => 'Your password has been changed. Please log in again.',
    'invalid_code' => 'The code is wrong or expired. Ask for a new code.',
    'invalid_code_field' => 'The code is wrong or expired.',

    // Tricycle
    'reason_plate_changed' => 'The plate number changed. Upload the OR/CR and MTOP that show the new plate.',

    // Documents
    'tricycle_required' => 'Add your tricycle first.',
    'files_invalid' => 'Please check the files.',
    'files_count' => '{1} Upload 1 file.|[2,*] Upload :count files.',
    'files_front_back' => 'Both the front and the back are needed.',

    // Admin review
    'document_not_pending' => "This document was already reviewed. Wait for the driver's new upload.",
    'expiry_required_to_approve' => 'Enter the expiry date before approving.',
    'document_already_expired' => "This document has already expired. It can't be approved.",

    // Password reset email
    'mail_reset_subject' => 'Papaya HatidGo: your code for a new password',
    'mail_reset_greeting' => 'Hi :name,',
    'mail_reset_intro' => 'Here is your code to create a new password in Papaya HatidGo:',
    'mail_reset_valid' => "It's valid for :minutes minutes. Enter it in the app together with your new password.",
    'mail_reset_ignore' => "If you didn't ask for this, ignore this email. Nothing will change on your account.",
    'mail_reset_warning' => 'Never give this code to anyone, even someone claiming to be from Papaya HatidGo.',

    // Subscriptions and payments (Phase 8)
    'payment_gateway_error' => "We couldn't reach the payment service. Please try again in a moment.",
    'plan_not_for_you' => "This plan isn't for your account.",
    'plan_inactive' => "This plan isn't available anymore.",
    'already_renewed' => "You've already renewed. Your next period starts on :date.",
    'subscription_not_pending' => "This payment can't be cancelled anymore.",
    'subscription_not_activatable' => 'Only an unpaid subscription can be activated by hand.',
    'subscription_inactive' => 'You need an active subscription for this.',
    'not_eligible' => "You can't go online yet. Check the list on Home.",

    // Payment return page (opened by PayMongo after paying or cancelling)
    'return_success_title' => 'Thank you! Go back to the app.',
    'return_success_body' => 'The app will confirm your payment.',
    'return_cancel_title' => 'Payment cancelled',
    'return_cancel_body' => 'Nothing was charged. You can try again in the app.',
    'return_button' => 'Back to Papaya HatidGo',
];
