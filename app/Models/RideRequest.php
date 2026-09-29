<?php

namespace App\Models;

use App\Enums\CancelledBy;
use App\Enums\RideLeg;
use App\Enums\RideStatus;
use App\Enums\RideType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One ride. Only what the PASSENGER chooses is fillable (where, where to, ride type, wait, note).
 * Status, driver, vehicle, fares and every timestamp are set by RideService / FareService:
 * a request body can never set its own fare or mark a ride completed.
 */
#[Fillable([
    'ride_type', 'wait_minutes',
    'pickup_lat', 'pickup_lng', 'pickup_landmark',
    'destination_lat', 'destination_lng', 'destination_landmark',
    'passenger_note',
])]
#[Hidden(['active_passenger_key', 'active_driver_key'])]
class RideRequest extends Model
{
    protected function casts(): array
    {
        return [
            'ride_type' => RideType::class,
            'status' => RideStatus::class,
            'current_leg' => RideLeg::class,
            'cancelled_by' => CancelledBy::class,
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'destination_lat' => 'decimal:7',
            'destination_lng' => 'decimal:7',
            'outbound_distance_km' => 'decimal:2',
            'return_distance_km' => 'decimal:2',
            'outbound_fare' => 'decimal:2',
            'return_fare' => 'decimal:2',
            'waiting_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'estimated_total' => 'decimal:2',
            'final_total' => 'decimal:2',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'arriving_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'destination_reached_at' => 'datetime',
            'return_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** "HG-000123": derived from the id, never stored (N14). */
    protected function referenceNo(): Attribute
    {
        return Attribute::get(fn () => 'HG-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT));
    }

    /** Balikan waiting time in minutes: derived from two timestamps, never stored (N13). */
    protected function waitingMinutes(): Attribute
    {
        return Attribute::get(function (): ?int {
            if (! $this->destination_reached_at) {
                return null;
            }
            $end = $this->return_started_at ?? now();

            return (int) $this->destination_reached_at->diffInMinutes($end);
        });
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function fareSetting(): BelongsTo
    {
        return $this->belongsTo(FareSetting::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(RideLocation::class)->orderBy('recorded_at');
    }

    public function rating(): HasOne
    {
        return $this->hasOne(Rating::class);
    }
}
