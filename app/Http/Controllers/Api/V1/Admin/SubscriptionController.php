<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin side of subscriptions (Phase 8). Postman until the admin web (Phase 14), which adds
 * plan editing and reports.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** GET /admin/subscriptions?state=pending&email=… : find the subscription to act on (newest 50). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'state' => ['nullable', 'in:pending,paid,cancelled,suspended'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        return SubscriptionResource::collection(
            Subscription::query()
                ->with(['plan', 'transactions'])
                ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state))
                ->when($filters['email'] ?? null, fn ($q, $email) => $q->whereHas('user', fn ($u) => $u->where('email', $email)))
                ->latest('id')
                ->limit(50)
                ->get(),
        );
    }

    /**
     * POST /admin/subscriptions/{id}/activate {reason}: the demo fallback (guarantee #6), for when
     * the gateway can't be reached. Written to the audit log with the admin and the reason.
     */
    public function activate(ReasonRequest $request, int $subscription): SubscriptionResource
    {
        $activated = $this->subscriptions->activateManually(
            $request->user(),
            Subscription::findOrFail($subscription),
            $request->validated('reason'),
        );

        return new SubscriptionResource($activated->load(['plan', 'transactions']));
    }
}
