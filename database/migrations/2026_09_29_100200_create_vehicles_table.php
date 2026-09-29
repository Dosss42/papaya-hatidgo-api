<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->string('plate_number', 15)->unique();
            // LGU-assigned body number painted on the tricycle; unique in the town when present.
            $table->string('body_number', 20)->nullable()->unique();
            $table->string('make', 50)->nullable();
            $table->string('model', 50)->nullable();
            $table->string('color', 30);
            $table->enum('status', ['pending', 'verified', 'rejected', 'inactive'])->default('pending');
            $table->timestamps();

            // Target of the composite foreign key below: "vehicle X that belongs to driver Y".
            $table->unique(['id', 'driver_id']);
        });

        // The driver's active tricycle. A composite foreign key guarantees in the database that
        // (1) there is at most one active vehicle per driver, and (2) it belongs to THAT driver.
        // A boolean "is_primary" flag could guarantee neither.
        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedBigInteger('active_vehicle_id')->nullable()->after('user_id');
            $table->foreign(['active_vehicle_id', 'id'])
                ->references(['id', 'driver_id'])
                ->on('vehicles')
                ->restrictOnDelete(); // unset it before deleting that vehicle
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['active_vehicle_id', 'id']);
            $table->dropColumn('active_vehicle_id');
        });
        Schema::dropIfExists('vehicles');
    }
};
