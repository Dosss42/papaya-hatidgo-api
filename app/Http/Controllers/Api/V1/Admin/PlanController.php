<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PlanAudience;
use App\Enums\SubscriptionState;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin: subscription plans (Phase 14).
 *   GET   /admin/subscription-plans        all plans + how many people hold each now
 *   POST  /admin/subscription-plans        a new plan
 *   PATCH /admin/subscription-plans/{id}   name, price, benefits, active, order
 * A price change applies to NEW purchases only: each subscription keeps the amount it was bought
 * at. Audience and length can't change once someone bought the plan (their meaning would change
 * under them): add a new plan instead and switch the old one off. Plans are never deleted.
 */
class PlanController extends Controller
{
    private const FIELDS = ['code', 'name', 'user_type', 'duration_months', 'price', 'benefits', 'is_active', 'sort_order'];

    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        $plans = $this->withCounts(SubscriptionPlan::query())
            ->orderBy('user_type')->orderBy('sort_order')->orderBy('duration_months')
            ->get();

        return response()->json(['data' => $plans->map(fn (SubscriptionPlan $p) => $this->row($p))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('subscription_plans', 'code')],
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'user_type' => ['required', Rule::enum(PlanAudience::class)],
            'duration_months' => ['required', 'integer', 'between:1,24'],
            'price' => ['required', 'numeric', 'between:1,100000'],
            'benefits' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'between:0,999'],
        ]);
        $plan = SubscriptionPlan::forceCreate($data + ['currency' => 'PHP', 'is_active' => true, 'sort_order' => 0]);
        $this->audit->record($request->user(), 'plan.created', $plan, null, $plan->only(self::FIELDS));

        return response()->json(['data' => $this->row($this->withCounts(SubscriptionPlan::query())->findOrFail($plan->id))], 201);
    }

    public function update(Request $request, int $plan): JsonResponse
    {
        $found = SubscriptionPlan::findOrFail($plan);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:80'],
            'user_type' => ['sometimes', Rule::enum(PlanAudience::class)],
            'duration_months' => ['sometimes', 'integer', 'between:1,24'],
            'price' => ['sometimes', 'numeric', 'between:1,100000'],
            'benefits' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,999'],
        ]);
        $changesMeaning = (isset($data['user_type']) && $data['user_type'] !== $found->user_type->value)
            || (isset($data['duration_months']) && (int) $data['duration_months'] !== $found->duration_months);
        if ($changesMeaning && $found->subscriptions()->exists()) {
            throw new ApiException(__('api.plan_in_use'), 'PLAN_IN_USE', 422);
        }

        $old = $found->only(array_keys($data));
        $found->forceFill($data)->save();
        if ($found->wasChanged()) {
            $this->audit->record($request->user(), 'plan.updated', $found, $old, $found->only(array_keys($data)));
        }

        return response()->json(['data' => $this->row($this->withCounts(SubscriptionPlan::query())->findOrFail($found->id))]);
    }

    /** How many hold each plan now (paid and in its period), and how many bought it ever. */
    private function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'subscriptions as active_count' => fn ($q) => $q->where('state', SubscriptionState::Paid)
                ->where('starts_at', '<=', now())->where('ends_at', '>', now()),
            'subscriptions as sold_count' => fn ($q) => $q->where('state', SubscriptionState::Paid),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(SubscriptionPlan $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'user_type' => $p->user_type->value,
            'duration_months' => $p->duration_months,
            'price' => $p->price,
            'currency' => $p->currency,
            'benefits' => $p->benefits,
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
            'active_count' => (int) ($p->active_count ?? 0),
            'sold_count' => (int) ($p->sold_count ?? 0),
        ];
    }
}
