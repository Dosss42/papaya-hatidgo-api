<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One checkout attempt with the payment gateway (PayMongo, test mode).
     * No user_id: the user is known through the subscription (3NF).
     * `amount` is what the GATEWAY reports: the webhook compares it with subscriptions.amount
     * (anti-tampering check from the payment protocol, phase-0 § F.2).
     */
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('PHP');
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'refunded'])->default('pending');
            $table->string('provider', 30)->default('paymongo');
            $table->string('provider_checkout_id', 100)->unique();
            $table->string('provider_payment_id', 100)->nullable()->unique();
            $table->string('payment_method', 30)->nullable(); // e.g. gcash, card
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // A subscription can be paid only once: at most one 'paid' transaction per subscription.
            $table->unsignedBigInteger('paid_key')
                ->storedAs("IF(status = 'paid', subscription_id, NULL)")
                ->nullable()
                ->unique();
        });

        DB::statement("ALTER TABLE subscription_transactions
            ADD CONSTRAINT chk_transaction_paid
                CHECK ((status IN ('paid', 'refunded')) = (paid_at IS NOT NULL)),
            ADD CONSTRAINT chk_transaction_amount
                CHECK (amount >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
