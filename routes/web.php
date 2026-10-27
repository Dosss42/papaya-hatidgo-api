<?php

use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;

// The app's download page and user manual (static files in public/download/). The home address
// opens it too, so the server's plain address shows something useful (the API needs no Vite page).
// To the file itself: Laravel ignores a trailing slash, so "/download/" would match this route again.
Route::redirect('/', '/download/index.html');
Route::redirect('/download', '/download/index.html');

// Payment pages a BROWSER opens (Phase 8). The API itself is in routes/api.php.
Route::prefix('payments')->name('payments.')->controller(PaymentPageController::class)->group(function () {
    // PayMongo's success_url / cancel_url → "Back to the app" (proves nothing; the app asks the API).
    Route::get('/return', 'return')->name('return');

    // The local test checkout (PAYMENT_GATEWAY=fake only; 404 otherwise and in production).
    Route::get('/fake-checkout/{checkout}', 'fakeShow')->name('fake.show');
    Route::post('/fake-checkout/{checkout}/pay', 'fakePay')->name('fake.pay');
    Route::post('/fake-checkout/{checkout}/cancel', 'fakeCancel')->name('fake.cancel');
});
