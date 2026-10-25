<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FareSetting;
use App\Services\AuditService;
use App\Services\FareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin: fares (Phase 14). INSERT-ONLY (decision #5): a new fare is a new row with the time it
 * takes effect; rows are never edited, so every past ride keeps the fare it was priced with
 * (ride_requests.fare_setting_id) and the history stays readable.
 *   GET  /admin/fare-settings   history, newest first, with which one is current
 *   POST /admin/fare-settings   a new fare, effective now or at a later time
 */
class FareSettingController extends Controller
{
    private const FIELDS = ['base_fare', 'rate_per_km', 'minimum_fare', 'return_rate_multiplier', 'waiting_free_minutes', 'waiting_fee_per_minute', 'service_fee'];

    public function __construct(
        private readonly FareService $fares,
        private readonly AuditService $audit,
    ) {}

    public function index(): JsonResponse
    {
        $current = FareSetting::query()->current()->first();

        return response()->json(['data' => FareSetting::query()
            ->with('creator')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (FareSetting $f) => $this->row($f) + [
                'is_current' => $current?->id === $f->id,
                'is_scheduled' => $f->effective_from->isFuture(),
            ])]);
    }

    public function store(Request $request): JsonResponse
    {
        // Ranges keep a typo from pricing every ride in town absurdly (decision #6).
        $data = $request->validate([
            'base_fare' => ['required', 'numeric', 'between:0,1000'],
            'rate_per_km' => ['required', 'numeric', 'between:0,500'],
            'minimum_fare' => ['required', 'numeric', 'between:0,2000'],
            'return_rate_multiplier' => ['required', 'numeric', 'between:0,3'],
            'waiting_free_minutes' => ['required', 'integer', 'between:0,120'],
            'waiting_fee_per_minute' => ['required', 'numeric', 'between:0,100'],
            'service_fee' => ['required', 'numeric', 'between:0,500'],
            'effective_from' => ['nullable', 'date', 'after_or_equal:now -1 minute'],
        ]);
        $before = FareSetting::query()->current()->first();
        $fare = FareSetting::forceCreate(
            collect($data)->only(self::FIELDS)->all() + [
                'effective_from' => isset($data['effective_from']) ? now()->parse($data['effective_from'])->utc() : now(),
                'created_by' => $request->user()->id,
            ],
        );
        $this->audit->record($request->user(), 'fare.created', $fare, $before?->only(self::FIELDS), $fare->only([...self::FIELDS, 'effective_from']));

        return response()->json(['data' => $this->row($fare->refresh()->load('creator'))], 201);
    }

    /** @return array<string, mixed> */
    private function row(FareSetting $f): array
    {
        return [
            'id' => $f->id,
            'base_fare' => $f->base_fare,
            'rate_per_km' => $f->rate_per_km,
            'minimum_fare' => $f->minimum_fare,
            'return_rate_multiplier' => $f->return_rate_multiplier,
            'waiting_free_minutes' => $f->waiting_free_minutes,
            'waiting_fee_per_minute' => $f->waiting_fee_per_minute,
            'service_fee' => $f->service_fee,
            'effective_from' => $f->effective_from->toIso8601ZuluString(),
            'created_by' => $f->creator?->full_name,
            'created_at' => $f->created_at?->toIso8601ZuluString(),
        ];
    }
}
