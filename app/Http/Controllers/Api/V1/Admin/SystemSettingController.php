<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin: system settings (Phase 14): ride types, matching, the service area, the grace days.
 *   GET   /admin/system-settings   every setting with its type, description and last change
 *   PATCH /admin/system-settings   {settings: {key: value, …}}: only the keys listed in RULES,
 *                                  each checked by type AND range (decision #6), all or nothing
 * Each changed key is audited with its old → new value.
 */
class SystemSettingController extends Controller
{
    /** The editable keys and what each may be. Anything else is refused. */
    private const RULES = [
        'ride.one_way_enabled' => ['boolean'],
        'ride.two_way_enabled' => ['boolean'],
        'matching.radius_km' => ['numeric', 'between:0.5,20'],
        'matching.board_radius_km' => ['numeric', 'between:1,20'],
        'matching.max_offers' => ['integer', 'between:1,20'],
        'matching.request_timeout_seconds' => ['integer', 'between:30,600'],
        'matching.stale_online_minutes' => ['integer', 'between:1,15'],
        'ride.min_distance_m' => ['integer', 'between:0,5000'],
        'ride.passenger_cancel_after_minutes' => ['integer', 'between:1,60'],
        'subscription.grace_days' => ['integer', 'between:0,30'],
        // The Philippines spans about 4.5–21.5 °N and 116–127 °E.
        'service_area.center_lat' => ['numeric', 'between:4,22'],
        'service_area.center_lng' => ['numeric', 'between:116,127'],
        'service_area.radius_km' => ['numeric', 'between:0.5,50'],
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => SystemSetting::query()->with('updater')->orderBy('key')->get()->map(fn (SystemSetting $s) => [
            'key' => $s->key,
            'value' => $s->typedValue(),
            'type' => $s->type,
            'description' => $s->description,
            'editable' => array_key_exists($s->key, self::RULES),
            'updated_by' => $s->updater?->full_name,
            'updated_at' => $s->updated_at?->toIso8601ZuluString(),
        ])]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate(['settings' => ['required', 'array', 'min:1']]);
        $input = $request->input('settings');
        $unknown = array_diff(array_keys($input), array_keys(self::RULES));
        if ($unknown) {
            throw ValidationException::withMessages(['settings' => __('api.setting_unknown', ['keys' => implode(', ', $unknown)])]);
        }
        $rules = [];
        $names = [];
        foreach (array_keys($input) as $key) {
            $field = 'settings.'.str_replace('.', '\.', $key);
            $rules[$field] = ['required', ...self::RULES[$key]];
            $names[$field] = __('validation.attributes.value'); // "The value must be between 30 and 600." under its own field
        }
        $request->validate($rules, [], $names);

        // At least one ride type must stay on, or nobody can book at all.
        $current = SystemSetting::query()->whereIn('key', ['ride.one_way_enabled', 'ride.two_way_enabled'])->pluck('value', 'key');
        $oneWay = filter_var($input['ride.one_way_enabled'] ?? $current['ride.one_way_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $twoWay = filter_var($input['ride.two_way_enabled'] ?? $current['ride.two_way_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (! $oneWay && ! $twoWay) {
            throw new ApiException(__('api.both_ride_types_off'), 'RIDE_TYPES_ALL_OFF', 422);
        }

        DB::transaction(function () use ($request, $input) {
            foreach ($input as $key => $value) {
                $setting = SystemSetting::query()->where('key', $key)->firstOrFail();
                $stored = match ($setting->type) {
                    'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                    'int' => (string) (int) $value,
                    default => (string) $value,
                };
                if ($stored === $setting->value) {
                    continue;
                }
                $old = $setting->typedValue();
                $setting->forceFill(['value' => $stored, 'updated_by' => $request->user()->id])->save();
                $this->audit->record($request->user(), 'setting.changed', $setting, [$key => $old], [$key => $setting->typedValue()]);
            }
        });

        return $this->index();
    }
}
