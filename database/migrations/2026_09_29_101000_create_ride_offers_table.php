<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which drivers a ride was offered to, and how each offer ended.
     * There is deliberately no 'accepted' status: who won the ride is recorded once,
     * in ride_requests.driver_id. When the ride is resolved, open offers become 'closed'.
     */
    public function up(): void
    {
        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['offered', 'declined', 'expired', 'closed'])->default('offered');
            $table->timestamp('offered_at')->useCurrent();
            $table->timestamp('responded_at')->nullable();

            // A ride is offered to the same driver at most once.
            $table->unique(['ride_request_id', 'driver_id']);
            // Driver app polling: "my open offers".
            $table->index(['driver_id', 'status']);
        });

        DB::statement("ALTER TABLE ride_offers
            ADD CONSTRAINT chk_offer_responded
                CHECK ((status = 'offered') = (responded_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_offers');
    }
};
