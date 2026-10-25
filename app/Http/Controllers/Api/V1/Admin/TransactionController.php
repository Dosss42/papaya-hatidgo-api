<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/admin/subscription-transactions?status=&q=&from=&to= (Phase 14): every payment
 * attempt (PayMongo, the test gateway, or manual), newest first, 25 per page. `q` finds a
 * person by name or email; from / to are Philippine calendar dates.
 */
class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $tz = config('app.business_timezone');

        $page = SubscriptionTransaction::query()
            ->with(['subscription.user', 'subscription.plan'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->whereHas('subscription.user', fn ($u) => $u->where('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
            })
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', now($tz)->parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', now($tz)->parse($d, $tz)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (SubscriptionTransaction $t) => [
                'id' => $t->id,
                'subscription_id' => $t->subscription_id,
                'user' => ['id' => $t->subscription->user->id, 'full_name' => $t->subscription->user->full_name, 'email' => $t->subscription->user->email, 'role' => $t->subscription->user->role->value],
                'plan' => $t->subscription->plan->name,
                'amount' => $t->amount,
                'status' => $t->status->value,
                'provider' => $t->provider,
                'payment_method' => $t->payment_method,
                'paid_at' => $t->paid_at?->toIso8601ZuluString(),
                'failure_reason' => $t->failure_reason,
                'created_at' => $t->created_at?->toIso8601ZuluString(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
