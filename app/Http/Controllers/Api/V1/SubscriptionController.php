<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\SubscriptionTransactionResource;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTransaction;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Subscriptions for passengers and drivers (Phase 8, phase-0 § G "Subscriptions and payments").
 * Everything is the caller's own: another user's subscription id answers 404.
 */
class SubscriptionController extends Controller
{
    private const HISTORY_LIMIT = 50;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** GET /subscription-plans: the plans for MY role. */
    public function plans(Request $request): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection($this->subscriptions->plansFor($request->user()));
    }

    /** GET /subscriptions/current: status, the current period, a waiting renewal, an unpaid checkout. */
    public function current(Request $request): JsonResponse
    {
        $summary = $this->subscriptions->summary($request->user());
        $resource = fn (?Subscription $s) => $s ? new SubscriptionResource($s->load(['plan', 'transactions'])) : null;

        return response()->json(['data' => [
            'status' => $summary['status'],
            'active_until' => $summary['active_until']?->toIso8601ZuluString(),
            'remaining_days' => $summary['remaining_days'],
            'current' => $resource($summary['current']),
            'renewal' => $resource($summary['renewal']),
            'pending' => $resource($summary['pending']),
            // Whether payments move real money: the app's "Test mode" tag follows the GATEWAY,
            // not the app's build (a dev build on live keys must never say "no real money").
            'test_mode' => self::testMode(),
        ]]);
    }

    private static function testMode(): bool
    {
        return config('payments.gateway') === 'fake'
            || str_starts_with((string) config('services.paymongo.secret_key'), 'sk_test_');
    }

    /** GET /subscriptions: my periods, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SubscriptionResource::collection(
            $request->user()->subscriptions()->with(['plan', 'transactions'])->latest('id')->limit(self::HISTORY_LIMIT)->get(),
        );
    }

    /** POST /subscriptions {plan_id} → a pending subscription + the page to pay on. */
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $result = $this->subscriptions->startCheckout(
            $request->user(),
            SubscriptionPlan::findOrFail($request->validated('plan_id')),
        );

        return response()->json(['data' => [
            'subscription' => new SubscriptionResource($result['subscription']->load(['plan', 'transactions'])),
            'checkout_url' => $result['checkout_url'],
        ]], 201);
    }

    /** GET /subscriptions/{id}: the app polls this after the payment page closes (reconciles if still pending). */
    public function show(Request $request, int $subscription): SubscriptionResource
    {
        $fresh = $this->subscriptions->reconcile($this->mine($request, $subscription));

        return new SubscriptionResource($fresh->load(['plan', 'transactions']));
    }

    /** POST /subscriptions/{id}/cancel: back out of an unpaid checkout. */
    public function cancel(Request $request, int $subscription): SubscriptionResource
    {
        $fresh = $this->subscriptions->cancel($this->mine($request, $subscription));

        return new SubscriptionResource($fresh->load(['plan', 'transactions']));
    }

    /** GET /subscription-transactions: my payment attempts, newest first. */
    public function transactions(Request $request): AnonymousResourceCollection
    {
        return SubscriptionTransactionResource::collection(
            SubscriptionTransaction::query()
                ->whereHas('subscription', fn ($q) => $q->where('user_id', $request->user()->id))
                ->with('subscription.plan')
                ->latest('id')
                ->limit(self::HISTORY_LIMIT)
                ->get(),
        );
    }

    private function mine(Request $request, int $id): Subscription
    {
        return $request->user()->subscriptions()->findOrFail($id); // 404 if not theirs
    }
}
