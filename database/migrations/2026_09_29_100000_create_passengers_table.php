<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passenger subtype of users (1:1). It exists so rides and ratings can reference a
     * passenger specifically. Passenger-only columns (e.g. emergency contact for SOS)
     * are added in the phase that needs them, not before.
     */
    public function up(): void
    {
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            // unique() makes the relationship 1:1; restrictOnDelete() protects ride history.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};
