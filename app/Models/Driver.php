<?php

namespace App\Models;

use App\Enums\ComplianceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Driver subtype of User (1:1).
 * Nothing is fillable: compliance_status (DriverComplianceService), is_online and location
 * (availability/location endpoints) and active_vehicle_id are all set by services.
 */
#[Fillable([])]
class Driver extends Model
{
    protected function casts(): array
    {
        return [
            'compliance_status' => ComplianceStatus::class,
            'is_online' => 'boolean',
            'current_lat' => 'decimal:7',
            'current_lng' => 'decimal:7',
            'location_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** The tricycle used for rides (DB-guaranteed to be one of this driver's own vehicles). */
    public function activeVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'active_vehicle_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function rideRequests(): HasMany
    {
        return $this->hasMany(RideRequest::class);
    }

    public function rideOffers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    /** Ratings reach the driver THROUGH the ride (ratings store only ride_request_id: 3NF). */
    public function ratings(): HasManyThrough
    {
        return $this->hasManyThrough(Rating::class, RideRequest::class);
    }
}
