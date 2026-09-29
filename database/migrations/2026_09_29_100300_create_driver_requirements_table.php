<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a driver must submit (configured by the admin; seeded in Step 4.8).
     * Upload rules that are the SAME for every requirement (JPG/PNG/PDF, 5 MB) live once in
     * config, not repeated per row. Only what differs per requirement is stored here.
     */
    public function up(): void
    {
        Schema::create('driver_requirements', function (Blueprint $table) {
            $table->id();
            // Stable identifier for code and seeders (names can be renamed by the admin; codes can't).
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            // Is it about the person (license, clearance) or the tricycle (OR/CR, MTOP)?
            $table->enum('applies_to', ['driver', 'vehicle']);
            // License = 2 (front + back); the others = 1.
            $table->unsignedTinyInteger('max_files')->default(1);
            $table->boolean('is_required')->default(true);
            // Critical = an expired/invalid one blocks going online.
            $table->boolean('is_critical')->default(true);
            $table->boolean('requires_expiry')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_requirements');
    }
};
