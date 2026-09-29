<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned fare rules, INSERT-ONLY: a fare change adds a new row and never edits an old one,
     * so every ride keeps pointing at the exact rules it was priced with.
     * The active version is the newest row with effective_from <= now (no is_active flag:
     * that would be a second, contradictable copy of the same fact).
     */
    public function up(): void
    {
        Schema::create('fare_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('base_fare', 10, 2);
            $table->decimal('rate_per_km', 10, 2);
            $table->decimal('minimum_fare', 10, 2);
            $table->decimal('return_rate_multiplier', 4, 2)->default(1.00);
            $table->unsignedSmallInteger('waiting_free_minutes')->default(0);
            $table->decimal('waiting_fee_per_minute', 10, 2)->default(0);
            $table->decimal('service_fee', 10, 2)->default(0);
            $table->timestamp('effective_from')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('effective_from');
        });

        DB::statement("ALTER TABLE fare_settings
            ADD CONSTRAINT chk_fare_amounts CHECK (
                base_fare >= 0 AND rate_per_km >= 0 AND minimum_fare >= 0
                AND waiting_fee_per_minute >= 0 AND service_fee >= 0
                AND return_rate_multiplier > 0
            )");
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_settings');
    }
};
