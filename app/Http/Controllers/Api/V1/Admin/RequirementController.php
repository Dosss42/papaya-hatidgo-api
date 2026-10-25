<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RequirementScope;
use App\Http\Controllers\Controller;
use App\Models\DriverRequirement;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin: the driver requirements (phase-0 § D, "admin-configurable"), Phase 14.
 *   GET   /admin/driver-requirements          all, in the drivers' order
 *   POST  /admin/driver-requirements          add one
 *   PATCH /admin/driver-requirements/{id}     change one (the code is permanent: apps refer to it)
 * Requirements are switched off (is_active), never deleted: uploaded papers point to them.
 * A driver's compliance is recalculated whenever it's read, so a change applies at once.
 */
class RequirementController extends Controller
{
    private const FIELDS = ['code', 'name', 'description', 'applies_to', 'max_files', 'is_required', 'is_critical', 'requires_expiry', 'is_active', 'sort_order'];

    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => DriverRequirement::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => $this->row($r))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('driver_requirements', 'code')],
            ...$this->rules(),
        ]);
        $requirement = DriverRequirement::forceCreate($data + ['max_files' => 1, 'is_required' => true, 'is_critical' => true, 'requires_expiry' => true, 'is_active' => true, 'sort_order' => 0]);
        $this->audit->record($request->user(), 'requirement.created', $requirement, null, $requirement->only(self::FIELDS));

        return response()->json(['data' => $this->row($requirement->refresh())], 201);
    }

    public function update(Request $request, int $requirement): JsonResponse
    {
        $found = DriverRequirement::findOrFail($requirement);
        $data = $request->validate(array_map(fn ($r) => ['sometimes', ...array_diff($r, ['required'])], $this->rules()));
        $old = $found->only(array_keys($data));
        $found->forceFill($data)->save();
        if ($found->wasChanged()) {
            $this->audit->record($request->user(), 'requirement.updated', $found, $old, $found->only(array_keys($data)));
        }

        return response()->json(['data' => $this->row($found->refresh())]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'applies_to' => ['required', Rule::enum(RequirementScope::class)],
            'max_files' => ['integer', 'between:1,4'], // e.g. a license: front + back = 2
            'is_required' => ['boolean'],
            'is_critical' => ['boolean'],
            'requires_expiry' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'between:0,999'],
        ];
    }

    /** @return array<string, mixed> */
    private function row(DriverRequirement $r): array
    {
        return [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'description' => $r->description,
            'applies_to' => $r->applies_to->value,
            'max_files' => $r->max_files,
            'is_required' => (bool) $r->is_required,
            'is_critical' => (bool) $r->is_critical,
            'requires_expiry' => (bool) $r->requires_expiry,
            'is_active' => (bool) $r->is_active,
            'sort_order' => $r->sort_order,
        ];
    }
}
