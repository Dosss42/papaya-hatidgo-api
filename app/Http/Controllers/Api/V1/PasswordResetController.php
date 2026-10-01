<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    /** POST /auth/forgot-password → ALWAYS the same 200 answer (no account enumeration). */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->resets->sendCode($request->validated('email'));

        return response()->json([
            'message' => __('api.reset_code_sent', ['minutes' => PasswordResetService::CODE_LIFETIME_MINUTES]),
        ]);
    }

    /** POST /auth/reset-password → 200, or 422 INVALID_OR_EXPIRED_CODE */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->resets->reset(
            $request->validated('email'),
            $request->validated('code'),
            $request->validated('password'),
        );

        return response()->json(['message' => __('api.password_changed')]);
    }
}
