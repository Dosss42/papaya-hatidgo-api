<?php

/*
 * API messages in Taglish (locale "fil", the default). English: lang/en/api.php.
 * The app picks the language with the Accept-Language header (SetLocaleFromRequest).
 * Every key here MUST also exist in lang/en/api.php.
 */

return [
    // Error format (ApiErrorRenderer)
    'validation_failed' => 'May mali sa inilagay mo. Pakitingnan ang mga field.',
    'forbidden' => 'Hindi ka pinapayagang gawin ito.',
    'not_found' => 'Hindi nahanap ang hinahanap mo.',
    'too_many_attempts' => 'Masyadong maraming subok. Maghintay muna bago umulit.',
    'method_not_allowed' => 'Hindi suportado ang request na ito.',
    'http_error' => 'May problema sa request.',
    'server_error' => 'May problema sa server. Subukan ulit mamaya.',

    // Auth
    'forbidden_role' => 'Hindi para sa account mo ang bahaging ito.',
    'invalid_credentials' => 'Mali ang email/mobile number o password.',
    'account_suspended' => 'Suspended ang account mo. Makipag-ugnayan sa admin ng Papaya HatidGo.',

    // Password reset
    'reset_code_sent' => 'Kung may account ang email na ito, nagpadala kami ng 6-digit code. Valid ito nang :minutes minuto.',
    'password_changed' => 'Napalitan na ang password mo. Mag-login ulit.',
    'invalid_code' => 'Mali o expired na ang code. Humingi ng bagong code.',
    'invalid_code_field' => 'Mali o expired na ang code.',

    // Tricycle
    'reason_plate_changed' => 'Nagbago ang plate number. I-upload ang OR/CR at MTOP na may bagong plate.',

    // Documents
    'tricycle_required' => 'Idagdag muna ang tricycle mo.',
    'files_invalid' => 'Tingnan ulit ang mga file.',
    'files_count' => '{1} Mag-upload ng 1 file.|[2,*] Mag-upload ng :count file.',
    'files_front_back' => 'Kailangan ang harap at likod.',

    // Admin review
    'document_not_pending' => 'Nasuri na ang dokumentong ito. Hintayin ang bagong upload ng driver.',
    'expiry_required_to_approve' => 'Ilagay ang expiry date bago aprubahan.',
    'document_already_expired' => 'Expired na ang dokumentong ito. Hindi ito maaaprubahan.',

    // Password reset email
    'mail_reset_subject' => 'Papaya HatidGo: ang code mo para sa bagong password',
    'mail_reset_greeting' => 'Hi :name,',
    'mail_reset_intro' => 'Ito ang code mo para makagawa ng bagong password sa Papaya HatidGo:',
    'mail_reset_valid' => 'Valid ito sa loob ng :minutes minuto. Ilagay ito sa app kasama ang bago mong password.',
    'mail_reset_ignore' => 'Kung hindi ikaw ang humingi nito, huwag pansinin ang email na ito. Walang magbabago sa account mo.',
    'mail_reset_warning' => 'Huwag ibigay ang code na ito kahit kanino, kahit sa nagpapakilalang taga-Papaya HatidGo.',

    // Subscriptions at bayad (Phase 8)
    'payment_gateway_error' => 'Hindi maabot ang payment service. Subukan ulit maya-maya.',
    'plan_not_for_you' => 'Hindi para sa account mo ang plan na ito.',
    'plan_inactive' => 'Hindi na available ang plan na ito.',
    'already_renewed' => 'Na-renew mo na. Magsisimula ang susunod mong period sa :date.',
    'subscription_not_pending' => 'Hindi na puwedeng i-cancel ang bayad na ito.',
    'subscription_not_activatable' => 'Ang hindi pa bayad na subscription lang ang puwedeng i-activate nang mano-mano.',
    'subscription_inactive' => 'Kailangan mo ng active na subscription para dito.',
    'not_eligible' => 'Hindi ka pa puwedeng mag-online. Tingnan ang listahan sa Home.',

    // Payment return page (binubuksan ng PayMongo pagkatapos magbayad o mag-cancel)
    'return_success_title' => 'Salamat! Bumalik na sa app.',
    'return_success_body' => 'Kukumpirmahin ng app ang bayad mo.',
    'return_cancel_title' => 'Na-cancel ang bayad',
    'return_cancel_body' => 'Walang nabawas. Puwede kang sumubok ulit sa app.',
    'return_button' => 'Bumalik sa Papaya HatidGo',
];
