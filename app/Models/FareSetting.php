<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A version of the fare rules. INSERT-ONLY: never update a row; create a new version.
 * The active version is derived (see current()), not stored in a flag.
 */
#[Fillable([
    'base_fare', 'rate_per_km', 'minimum_fare', 'return_rate_multiplier',
    'waiting_free_minutes', 'waiting_fee_per_minute', 'service_fee', 'effective_from',
])]
class FareSetting extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'base_fare' => 'decimal:2',
            'rate_per_km' => 'decimal:2',
            'minimum_fare' => 'decimal:2',
            'return_rate_multiplier' => 'decimal:2',
            'waiting_fee_per_minute' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'effective_from' => 'datetime',
        ];
    }

    /** FareSetting::current()->first(): the newest version already in effect. */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rideRequests(): HasMany
    {
        return $this->hasMany(RideRequest::class);
    }
}
