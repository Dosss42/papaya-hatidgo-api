<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ComplianceStatus;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/admin/driver-verifications: the admin's review queue (role:admin).
 *   ?status=to_review (default): drivers with at least one PENDING document, longest-waiting
 *     first; this includes verified drivers who uploaded a renewal.
 *   ?status=pending_verification|under_review|verified|rejected|expired: by compliance status.
 */
class DriverVerificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $statuses = array_column(ComplianceStatus::cases(), 'value');
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['to_review', ...$statuses])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $validated['status'] ?? 'to_review';

        $pending = fn ($q) => $q->where('status', DocumentStatus::Pending);
        $query = Driver::query()->with([
            'user',
            'activeVehicle',
            'documents' => fn ($q) => $pending($q)->with('requirement')->orderBy('submitted_at'),
        ]);

        if ($status === 'to_review') {
            $query->whereHas('documents', $pending)
                ->withMin(['documents as waiting_since' => $pending], 'submitted_at')
                ->orderBy('waiting_since'); // fair: whoever has waited longest comes first
        } else {
            $query->where('compliance_status', $status)->orderBy('id');
        }

        $page = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Driver $d) => [
                'driver_id' => $d->id,
                'full_name' => $d->user->full_name,
                'phone' => $d->user->phone,
                'account_status' => $d->user->account_status->value,
                'compliance_status' => $d->compliance_status->value,
                'plate_number' => $d->activeVehicle?->plate_number,
                'documents_to_review' => $d->documents->map(fn (DriverDocument $doc) => [
                    'id' => $doc->id,
                    'requirement' => $doc->requirement->displayName(),
                    'is_renewal' => ! $doc->is_current,
                    'submitted_at' => $doc->submitted_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
