<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tricycle. status is not fillable: DriverComplianceService sets pending/verified automatically
 * from its papers (Phase 7 decision #1); only an admin sets rejected/inactive.
 */
#[Fillable(['plate_number', 'body_number', 'make', 'model', 'color'])]
class Vehicle extends Model
{
    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }
}
