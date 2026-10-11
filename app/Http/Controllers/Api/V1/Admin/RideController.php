<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RideStatus;
use App\Enums\RideType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReasonRequest;
use App\Http\Resources\RideResource;
use App\Models\RideRequest;
use App\Services\RideService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Admin: watch rides and cancel a stuck one (Phase 9; the dashboard screens come in Phase 14). */
class RideController extends Controller
{
    public function __construct(private readonly RideService $rides) {}

    /** GET /admin/rides?status=&type= (newest 50). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RideStatus::class)],
            'type' => ['nullable', Rule::enum(RideType::class)],
        ]);

        return RideResource::collection(
            RideRequest::query()
                ->with(['passenger.user', 'driver.user', 'vehicle', 'rating'])
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('ride_type', $t))
                ->latest('id')
                ->limit(50)
                ->get(),
        );
    }

    /** POST /admin/rides/{id}/cancel {reason}: any active ride, written to the audit log. */
    public function cancel(ReasonRequest $request, int $ride): RideResource
    {
        $cancelled = $this->rides->cancel($request->user(), RideRequest::findOrFail($ride), $request->validated('reason'));

        return new RideResource($cancelled->load(['passenger.user', 'driver.user', 'vehicle', 'rating']));
    }
}
