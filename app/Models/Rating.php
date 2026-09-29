<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * The passenger's rating of the driver for one ride.
 * Stores only ride_request_id (3NF, N11); passenger and driver are reached THROUGH the ride.
 */
#[Fillable(['score', 'comment'])]
class Rating extends Model
{
    public const UPDATED_AT = null;

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    /** rating → ride → driver */
    public function driver(): HasOneThrough
    {
        return $this->hasOneThrough(Driver::class, RideRequest::class, 'id', 'id', 'ride_request_id', 'driver_id');
    }

    /** rating → ride → passenger */
    public function passenger(): HasOneThrough
    {
        return $this->hasOneThrough(Passenger::class, RideRequest::class, 'id', 'id', 'ride_request_id', 'passenger_id');
    }
}
