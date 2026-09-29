<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One point of the driver's route during a ride (the driver is known from the ride). */
#[Fillable(['lat', 'lng', 'accuracy_m', 'recorded_at'])]
#[WithoutTimestamps]
class RideLocation extends Model
{
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'accuracy_m' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }
}
