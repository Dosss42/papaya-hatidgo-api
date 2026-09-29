<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The driver's route trail during a ride. No driver_id column: the driver is already
     * known from the ride (storing it again would be a transitive dependency).
     */
    public function up(): void
    {
        Schema::create('ride_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->restrictOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_m', 7, 2)->nullable();
            $table->timestamp('recorded_at');

            $table->index(['ride_request_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_locations');
    }
};
