<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription plans configured by the admin (seeded: 1 / 6 / 12 months for each audience).
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->enum('user_type', ['passenger', 'driver']);
            // A number, not an enum: the admin can create any length without a schema change.
            $table->unsignedTinyInteger('duration_months');
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('PHP');
            $table->text('benefits')->nullable(); // display text only; no promises until decided
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE subscription_plans
            ADD CONSTRAINT chk_plan_values CHECK (duration_months > 0 AND price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
