<?php

use App\Http\Controllers\Api\V1\Admin\DriverAccountController as AdminDriverAccountController;
use App\Http\Controllers\Api\V1\Admin\DriverDocumentController as AdminDriverDocumentController;
use App\Http\Controllers\Api\V1\Admin\DriverVerificationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DriverDocumentController;
use App\Http\Controllers\Api\V1\DriverRequirementController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\VehicleController;
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

    // Driver: own tricycles (step 7.2) and documents (step 7.3), phase-7-driver-requirements.md
    Route::middleware(['auth:sanctum', 'role:driver'])->group(function () {
        Route::get('/driver-requirements', [DriverRequirementController::class, 'index']);
        Route::get('/drivers/me/requirements', [DriverRequirementController::class, 'mine']);
        Route::post('/drivers/me/documents', [DriverDocumentController::class, 'store'])->middleware('throttle:uploads');
        Route::get('/drivers/me/documents/{document}', [DriverDocumentController::class, 'show'])->whereNumber('document');

        Route::get('/vehicles', [VehicleController::class, 'index']);
        Route::post('/vehicles', [VehicleController::class, 'store']);
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->whereNumber('vehicle');
    });

    // Admin: driver verification (step 7.4). Postman until the admin web exists (Phase 14).
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('/driver-verifications', [DriverVerificationController::class, 'index']);

        Route::prefix('driver-documents/{document}')->whereNumber('document')->group(function () {
            Route::get('/', [AdminDriverDocumentController::class, 'show']);
            Route::get('/files/{file}', [AdminDriverDocumentController::class, 'file'])->whereNumber('file');
            Route::post('/approve', [AdminDriverDocumentController::class, 'approve']);
            Route::post('/reject', [AdminDriverDocumentController::class, 'reject']);
            Route::post('/request-resubmission', [AdminDriverDocumentController::class, 'requestResubmission']);
        });

        Route::post('/drivers/{driver}/suspend', [AdminDriverAccountController::class, 'suspend'])->whereNumber('driver');
        Route::post('/drivers/{driver}/reactivate', [AdminDriverAccountController::class, 'reactivate'])->whereNumber('driver');
    });
});
