<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One submission of one requirement. Only what the driver types is fillable;
 * status and is_current are set by DriverComplianceService. current_key is computed by MySQL.
 */
#[Fillable(['document_number', 'issued_at', 'expires_at'])]
#[Hidden(['current_key'])]
class DriverDocument extends Model
{
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'is_current' => 'boolean',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(DriverRequirement::class, 'driver_requirement_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(DriverDocumentFile::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DriverRequirementReview::class);
    }

    /** The most recent review: "who reviewed it, when" comes from here (not stored on the document). */
    public function latestReview(): HasOne
    {
        return $this->hasOne(DriverRequirementReview::class)->latestOfMany();
    }
}
