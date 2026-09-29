<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A ride offered to one driver. Who WON the ride is ride_requests.driver_id (N12). */
#[Fillable([])]
#[WithoutTimestamps]
class RideOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'offered_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
