<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DriverAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin: users (Phase 14).
 *   GET   /admin/users?role=&status=&q=   search (name, email or phone), 25 per page
 *   PATCH /admin/users/{id}/status         {status: active|suspended, reason (required to suspend)}
 * A driver goes through DriverAccountService (it also takes them offline); a passenger is
 * changed here. Admin accounts can't be changed from the website. Every change is audited.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly DriverAccountService $drivers,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = User::query()
            ->with('driver')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('account_status', $status))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => $this->row($u)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function updateStatus(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'reason' => ['required_if:status,suspended', 'nullable', 'string', 'min:3', 'max:255'],
        ]);
        $target = User::query()->with('driver')->findOrFail($user);
        if ($target->role === UserRole::Admin) {
            throw new ApiException(__('api.admin_account_locked'), 'ADMIN_ACCOUNT_LOCKED', 422);
        }
        $suspend = $data['status'] === AccountStatus::Suspended->value;

        if ($target->role === UserRole::Driver && $target->driver) {
            $suspend
                ? $this->drivers->suspend($request->user(), $target->driver, $data['reason'])
                : $this->drivers->reactivate($request->user(), $target->driver);
        } elseif ($target->account_status->value !== $data['status']) {
            DB::transaction(function () use ($request, $target, $data, $suspend) {
                $old = $target->account_status->value;
                $target->forceFill(['account_status' => $data['status']])->save();
                $this->audit->record($request->user(), $suspend ? 'user.suspended' : 'user.reactivated', $target,
                    ['account_status' => $old], ['account_status' => $data['status'], 'reason' => $data['reason'] ?? null]);
            });
        }

        return response()->json(['data' => $this->row($target->refresh()->load('driver'))]);
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role->value,
            'account_status' => $u->account_status->value,
            'created_at' => $u->created_at?->toIso8601ZuluString(),
            'driver' => $u->driver ? [
                'id' => $u->driver->id,
                'compliance_status' => $u->driver->compliance_status->value,
                'is_online' => (bool) $u->driver->is_online,
            ] : null,
        ];
    }
}
