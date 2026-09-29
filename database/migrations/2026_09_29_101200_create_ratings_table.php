<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The passenger's rating of the driver for one ride.
     * Only ride_request_id is stored: the passenger and the driver are already known from the
     * ride (3NF: rating → ride → passenger/driver; storing them here would duplicate that).
     */
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            // unique() = one rating per ride.
            $table->foreignId('ride_request_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE ratings ADD CONSTRAINT chk_rating_score CHECK (score BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
