<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\DriverComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The go-online gate (Phase 8 step 8.4, phase-0 § D.3 + § F.1).
 *
 * GET   /drivers/me/eligibility   → the checklist: account active, papers verified, tricycle
 *                                   verified, subscription active.
 * PATCH /drivers/me/availability  → {is_online}. Going ONLINE is refused (403 NOT_ELIGIBLE, with
 *                                   the checklist) unless every check passes. Going offline is
 *                                   always allowed. Phase 11 adds the location rules on top.
 */
class DriverAvailabilityController extends Controller
{
    public function __construct(private readonly DriverComplianceService $compliance) {}

    public function eligibility(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->compliance->eligibility($this->driver($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $wantsOnline = $request->validate(['is_online' => ['required', 'boolean']])['is_online'];
        $driver = $this->driver($request);
        $eligibility = $this->compliance->eligibility($driver); // recalculates first

        if ($wantsOnline && ! $eligibility['eligible']) {
            return response()->json([
                'message' => __('api.not_eligible'),
                'code' => 'NOT_ELIGIBLE',
                'data' => $eligibility, // so the app can show exactly which check failed
            ], 403);
        }

        $driver->refresh()->forceFill(['is_online' => (bool) $wantsOnline])->save();

        return response()->json(['data' => ['is_online' => $driver->is_online] + $eligibility]);
    }

    private function driver(Request $request): Driver
    {
        return $request->user()->driver()->firstOrFail();
    }
}
