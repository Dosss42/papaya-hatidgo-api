<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per paid period (a renewal is a new row).
     *
     * Only facts that do NOT change with the calendar are stored in `state`:
     *   pending (checkout created) · paid · cancelled · suspended (admin).
     * Whether a paid subscription is active, past_due (in grace) or expired depends on
     * today's date, so SubscriptionService COMPUTES it from starts_at / ends_at / grace days.
     * A stored "active" would silently become wrong at midnight.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->enum('state', ['pending', 'paid', 'cancelled', 'suspended'])->default('pending');
            // The price actually charged: plan prices may change later, so this is history, not a copy.
            $table->decimal('amount', 10, 2);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // "Is this user's subscription active right now?" (booking and go-online gates).
            $table->index(['user_id', 'state', 'ends_at']);
        });

        DB::statement("ALTER TABLE subscriptions
            ADD CONSTRAINT chk_subscription_period
                CHECK (state <> 'paid' OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at)),
            ADD CONSTRAINT chk_subscription_cancelled
                CHECK ((state = 'cancelled') = (cancelled_at IS NOT NULL)),
            ADD CONSTRAINT chk_subscription_amount
                CHECK (amount >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
