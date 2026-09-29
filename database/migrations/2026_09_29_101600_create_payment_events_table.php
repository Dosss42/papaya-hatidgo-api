<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every webhook received from the payment gateway, stored BEFORE it is processed.
     * The UNIQUE (provider, provider_event_id) is what makes webhook handling idempotent:
     * if PayMongo sends the same event twice, the second insert fails and it is not processed again.
     * The raw payload is kept as JSON on purpose: it is an external record kept for auditing,
     * not data the app queries relationally.
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('paymongo');
            $table->string('provider_event_id', 100);
            $table->string('event_type', 80);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
