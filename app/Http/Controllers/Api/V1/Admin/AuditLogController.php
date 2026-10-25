<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/audit-logs?action=&type=&actor_id=&from=&to= (Phase 14): the append-only
 * record of every admin write and system action on compliance and subscriptions (phase-0 § K),
 * newest first, 30 per page. Read-only: there is no endpoint that changes or deletes a log.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'], // a prefix: "ride." finds every ride action
            'type' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $tz = config('app.business_timezone');

        $page = AuditLog::query()
            ->with('actor')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('auditable_type', $t))
            ->when($filters['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', now($tz)->parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', now($tz)->parse($d, $tz)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'full_name' => $log->actor->full_name, 'role' => $log->actor->role->value] : null, // null = the system
                'subject' => ['type' => $log->auditable_type, 'id' => $log->auditable_id],
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601ZuluString(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
