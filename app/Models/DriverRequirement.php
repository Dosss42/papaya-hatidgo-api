<?php

namespace App\Models;

use App\Enums\RequirementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A requirement drivers must submit (admin-configured). */
#[Fillable([
    'code', 'name', 'description', 'applies_to', 'max_files',
    'is_required', 'is_critical', 'requires_expiry', 'is_active', 'sort_order',
])]
class DriverRequirement extends Model
{
    protected function casts(): array
    {
        return [
            'applies_to' => RequirementScope::class,
            'is_required' => 'boolean',
            'is_critical' => 'boolean',
            'requires_expiry' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }
}
