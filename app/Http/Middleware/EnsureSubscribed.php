<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The subscription gate (Phase 8 step 8.4, phase-0 § F.1): a route with `subscribed` answers
 * 403 SUBSCRIPTION_INACTIVE unless the user has an active subscription right now.
 * Used by the passenger booking route POST /rides from Phase 9. (Drivers are gated by the full
 * go-online checklist instead, DriverAvailabilityController.)
 */
class EnsureSubscribed
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $this->subscriptions->isActive($request->user())) {
            throw new ApiException(__('api.subscription_inactive'), 'SUBSCRIPTION_INACTIVE', 403);
        }

        return $next($request);
    }
}
