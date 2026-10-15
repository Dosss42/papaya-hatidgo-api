<?php

namespace App\Http\Resources;

use App\Enums\RideStatus;
use App\Enums\UserRole;
use App\Models\Rating;
use App\Services\GeoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ride as its PARTICIPANTS (and admins) see it (Phase 9). What each one may see differs
 * (phase-0 § K privacy, decision #7):
 *
 * - The passenger sees the driver's name, tricycle, rating and position, and the driver's
 *   phone only while the ride is matched and active (the call button).
 * - The driver sees the passenger's first name, and the phone only while matched and active.
 * - Nobody sees the other's phone before the match or after the ride.
 *
 * Load with: passenger.user, driver.user, vehicle, rating.
 */
class RideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $asPassenger = $viewer?->role === UserRole::Passenger;
        $asDriver = $viewer?->role === UserRole::Driver;
        $contact = $this->isMatchedAndActive() || $viewer?->role === UserRole::Admin;
        $done = $this->status === RideStatus::Completed;

        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'ride_type' => $this->ride_type->value,
            'status' => $this->status->value,
            'current_leg' => $this->current_leg?->value,
            'wait_minutes' => $this->wait_minutes,
            'pickup' => ['lat' => $this->pickup_lat, 'lng' => $this->pickup_lng, 'landmark' => $this->pickup_landmark],
            'destination' => ['lat' => $this->destination_lat, 'lng' => $this->destination_lng, 'landmark' => $this->destination_landmark],
            'passenger_note' => $this->passenger_note,
            'distance_km' => $this->outbound_distance_km,
            'return_distance_km' => $this->return_distance_km,
            'fare' => [
                'outbound_fare' => $this->outbound_fare,
                'return_fare' => $this->return_fare,
                'waiting_fee' => $this->waiting_fee,
                'service_fee' => $this->service_fee,
                'estimated_total' => $this->estimated_total,
                // What to show as "the" total: the final one once completed, the estimate before.
                'total' => $done ? $this->final_total : $this->estimated_total,
                'is_final' => $done,
            ],
            'waiting_minutes' => $this->waiting_minutes,             // derived (N13)
            'passenger_returning_at' => $this->passenger_returning_at?->toIso8601ZuluString(),
            'times' => collect([
                'requested_at', 'expires_at', 'accepted_at', 'arriving_at', 'arrived_at', 'started_at',
                'destination_reached_at', 'return_started_at', 'completed_at', 'cancelled_at',
            ])->mapWithKeys(fn ($f) => [$f => $this->{$f}?->toIso8601ZuluString()])->all(),
            'cancelled_by' => $this->cancelled_by?->value,
            'cancel_reason' => $this->cancel_reason,
            'driver' => $this->driver ? $this->driverBlock($contact, ! $asDriver) : null,
            'vehicle' => $this->vehicle ? [
                'plate_number' => $this->vehicle->plate_number,
                'body_number' => $this->vehicle->body_number,
                'color' => $this->vehicle->color,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
            ] : null,
            'passenger' => ! $asPassenger && $this->driver_id !== null ? [
                'first_name' => $this->passenger->user->first_name,
                'phone' => $contact ? $this->passenger->user->phone : null,
            ] : null,
            'rating' => $this->rating ? ['score' => $this->rating->score, 'comment' => $this->rating->comment] : null,
        ];
    }

    public const MIN_RATINGS_FOR_AVERAGE = 5;

    private function ratingAverage(\Illuminate\Database\Eloquent\Builder $ratings): ?float
    {
        return (clone $ratings)->count() >= self::MIN_RATINGS_FOR_AVERAGE ? round((float) (clone $ratings)->avg('score'), 1) : null;
    }

    /** @return array<string, mixed> */
    private function driverBlock(bool $contact, bool $withPosition): array
    {
        $user = $this->driver->user;
        $ratings = Rating::query()->whereHas('rideRequest', fn ($q) => $q->where('driver_id', $this->driver_id));
        $located = $withPosition && $this->isMatchedAndActive() && $this->driver->current_lat !== null;

        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone' => $contact ? $user->phone : null,
            // The average only from 5 ratings: a tiny sample says little (active-ride brief § 8).
            'rating' => ['average' => $this->ratingAverage($ratings), 'count' => $ratings->count()],
            // For "1.2 km ang layo" (active-ride brief): hidden when unknown, never "0 km".
            'location' => $located ? [
                'lat' => $this->driver->current_lat,
                'lng' => $this->driver->current_lng,
                'updated_at' => $this->driver->location_updated_at?->toIso8601ZuluString(),
                'distance_to_pickup_km' => round(GeoService::distanceKm(
                    (float) $this->driver->current_lat, (float) $this->driver->current_lng, (float) $this->pickup_lat, (float) $this->pickup_lng,
                ), 2),
            ] : null,
        ];
    }
}
