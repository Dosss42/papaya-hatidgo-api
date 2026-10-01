<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One line in audit_logs per admin (or system) action: who, what, on which record, before/after,
 * from which IP (phase-0 § K: every admin write is audit-logged). Rows are never edited or deleted.
 */
class AuditService
{
    /**
     * @param  string  $action  dotted name, e.g. driver_document.approved
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(?User $actor, string $action, Model $subject, ?array $old = null, ?array $new = null): AuditLog
    {
        return AuditLog::forceCreate([
            'actor_id' => $actor?->id, // null = the system
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(), // the short name from the morph map, e.g. driver_document
            'auditable_id' => $subject->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
