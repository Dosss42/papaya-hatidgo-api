<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Thin controller: validation happens in the Form Requests, rules in AuthService,
 * output shape in UserResource. Contracts: phase-5-authentication.md § 3.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** POST /auth/register → 201 { token, user } */
    public function register(RegisterRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->auth->register($request->validated());

        return response()->json(['token' => $token, 'user' => new UserResource($user)], 201);
    }

    /** POST /auth/login → 200 { token, user } */
    public function login(LoginRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->auth->login(
            $request->validated('login'),
            $request->validated('password'),
            $request->validated('device_name'),
        );

        return response()->json(['token' => $token, 'user' => new UserResource($user)]);
    }

    /** POST /auth/logout → 204 */
    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user());

        return response()->noContent();
    }

    /** GET /auth/me → who am I, plus what the app needs to route me (role, driver status, subscription). */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $this->subscriptions->current($user);

        return response()->json([
            'user' => new UserResource($user),
            'driver' => $user->role === UserRole::Driver && $user->driver
                ? [
                    'compliance_status' => $user->driver->compliance_status->value,
                    'active_vehicle_id' => $user->driver->active_vehicle_id,
                ]
                : null,
            'subscription' => $subscription
                ? [
                    'status' => $this->subscriptions->status($user)?->value,
                    'plan_id' => $subscription->subscription_plan_id,
                    'ends_at' => $subscription->ends_at->toIso8601ZuluString(),
                ]
                : null,
        ]);
    }
}
