<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Driver subtype of users (1:1).
     * active_vehicle_id is added by the vehicles migration (vehicles must exist first).
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();

            // DERIVED value, stored on purpose: computed from the driver's documents by
            // DriverComplianceService (its only writer) and read by matching on every ride
            // request. Suspension is NOT here; it lives in users.account_status.
            $table->enum('compliance_status', [
                'pending_verification',
                'under_review',
                'verified',
                'rejected',
                'expired',
            ])->default('pending_verification');

            // Current working state (not history: the ride trail is in ride_locations).
            $table->boolean('is_online')->default(false);
            $table->decimal('current_lat', 10, 7)->nullable();
            $table->decimal('current_lng', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();

            $table->timestamps();

            // Matching filters on these first, then computes distance.
            $table->index(['compliance_status', 'is_online']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
