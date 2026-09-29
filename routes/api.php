<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use Illuminate\Support\Facades\Route;

/*
| Papaya HatidGo API. Everything in this file is served under /api (bootstrap/app.php).
| All endpoints live in the v1 group: /api/v1/...  (phase-0-analysis.md § G).
| Versioning lets a future v2 change responses without breaking installed apps.
*/

Route::prefix('v1')->group(function () {
    // Public
    Route::get('/health', HealthController::class);

    // Authentication (phase-5-authentication.md § 3)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:forgot-password');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });
});
