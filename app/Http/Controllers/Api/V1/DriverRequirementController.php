<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RequirementResource;
use App\Http\Resources\RequirementStateResource;
use App\Models\DriverRequirement;
use App\Services\DriverComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** What drivers must submit, and where THIS driver stands (role:driver on the routes). */
class DriverRequirementController extends Controller
{
    public function __construct(private readonly DriverComplianceService $compliance) {}

    /** GET /driver-requirements: the active definitions, in the admin's order. */
    public function index(): AnonymousResourceCollection
    {
        return RequirementResource::collection(
            DriverRequirement::where('is_active', true)->orderBy('sort_order')->get(),
        );
    }

    /** GET /drivers/me/requirements: the checklist behind Driver Home and the requirements list. */
    public function mine(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->firstOrFail();
        $status = $this->compliance->recalculate($driver); // fresh, e.g. if something expired an hour ago

        $states = $this->compliance->requirementStates($driver->refresh());
        $states->each(fn ($s) => $s->current?->loadMissing('latestReview'));

        return response()->json([
            'compliance_status' => $status->value,
            'requirements' => RequirementStateResource::collection($states),
        ]);
    }
}
