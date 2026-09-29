<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One ride, from request to completion or cancellation (state machine: phase-0 § E).
     * Not stored because they are derivable: reference number (from id), waiting minutes
     * (from two timestamps), who rated it (ratings point here). final_total IS stored,
     * but as a generated column MySQL computes, so it can never disagree with its parts.
     */
    public function up(): void
    {
        Schema::create('ride_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->constrained()->restrictOnDelete();
            // Set when a driver accepts. The single source of truth for "who got this ride".
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            // The fare rules this ride was priced with (rates are not copied here).
            $table->foreignId('fare_setting_id')->constrained()->restrictOnDelete();

            $table->enum('ride_type', ['one_way', 'two_way']);
            $table->enum('status', [
                'requested', 'accepted', 'driver_arriving', 'arrived', 'in_progress', 'completed', 'cancelled',
            ])->default('requested');
            // Two-way only, and only while in_progress.
            $table->enum('current_leg', ['outbound', 'waiting', 'return'])->nullable();
            // Balikan: the passenger's expected stay. NULL = "Hindi pa sigurado" (or one-way).
            $table->unsignedSmallInteger('wait_minutes')->nullable();

            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_landmark', 120)->nullable();
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_landmark', 120)->nullable();
            $table->string('passenger_note', 200)->nullable();

            $table->decimal('outbound_distance_km', 6, 2);
            $table->decimal('return_distance_km', 6, 2)->nullable();

            // Fare breakdown (estimated at request; updated to final values at completion).
            $table->decimal('outbound_fare', 10, 2);
            $table->decimal('return_fare', 10, 2)->nullable();
            $table->decimal('waiting_fee', 10, 2)->default(0);
            $table->decimal('service_fee', 10, 2)->default(0);
            // What the passenger saw before tapping Mag-book: a historical fact, not derivable later.
            $table->decimal('estimated_total', 10, 2);
            // Always the sum of the parts, computed by MySQL itself.
            $table->decimal('final_total', 10, 2)
                ->storedAs('outbound_fare + IFNULL(return_fare, 0) + waiting_fee + service_fee');

            // Lifecycle timestamps (each records when a transition happened).
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('arriving_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('destination_reached_at')->nullable();
            $table->timestamp('return_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('cancelled_by', ['passenger', 'driver', 'system', 'admin'])->nullable();
            $table->string('cancel_reason', 100)->nullable();

            $table->timestamps();

            // The vehicle used must belong to the assigned driver.
            $table->foreign(['vehicle_id', 'driver_id'])
                ->references(['id', 'driver_id'])
                ->on('vehicles')
                ->restrictOnDelete();

            // "One active ride per passenger" and "per driver", enforced by MySQL.
            $table->unsignedBigInteger('active_passenger_key')
                ->storedAs("IF(status IN ('requested','accepted','driver_arriving','arrived','in_progress'), passenger_id, NULL)")
                ->nullable()
                ->unique();
            $table->unsignedBigInteger('active_driver_key')
                ->storedAs("IF(status IN ('accepted','driver_arriving','arrived','in_progress'), driver_id, NULL)")
                ->nullable()
                ->unique();

            $table->index(['status', 'expires_at']);        // expiring unaccepted requests
            $table->index(['passenger_id', 'requested_at']); // passenger history
            $table->index(['driver_id', 'completed_at']);    // driver history + earnings
        });

        // status is kept for the state machine and queries; these CHECKs keep it consistent
        // with the other columns, so the redundancy can never turn into a contradiction.
        DB::statement("ALTER TABLE ride_requests
            ADD CONSTRAINT chk_ride_driver_assigned
                CHECK (status IN ('requested', 'cancelled') OR driver_id IS NOT NULL),
            ADD CONSTRAINT chk_ride_vehicle_needs_driver
                CHECK (vehicle_id IS NULL OR driver_id IS NOT NULL),
            ADD CONSTRAINT chk_ride_completed
                CHECK ((status = 'completed') = (completed_at IS NOT NULL)),
            ADD CONSTRAINT chk_ride_cancelled
                CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL)),
            ADD CONSTRAINT chk_ride_leg
                CHECK (current_leg IS NULL OR (ride_type = 'two_way' AND status = 'in_progress')),
            ADD CONSTRAINT chk_ride_one_way_fields
                CHECK (ride_type = 'two_way'
                       OR (return_fare IS NULL AND return_distance_km IS NULL AND wait_minutes IS NULL)),
            ADD CONSTRAINT chk_ride_amounts
                CHECK (outbound_fare >= 0 AND IFNULL(return_fare, 0) >= 0 AND waiting_fee >= 0
                       AND service_fee >= 0 AND estimated_total >= 0
                       AND outbound_distance_km >= 0 AND IFNULL(return_distance_km, 0) >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_requests');
    }
};
