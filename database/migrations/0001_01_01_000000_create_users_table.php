<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One login table for every role. Role-specific data lives in `passengers` / `drivers`
        // (subtype tables), so no column here is empty for one role and used by another.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Atomic name parts (1NF): the apps address people by first name ("Papunta na si Juan").
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email')->unique();
            // Stored in one format (E.164, e.g. +639171234567) so uniqueness works: 0917… and +63917… are the same phone.
            $table->string('phone', 20)->unique();
            $table->enum('role', ['passenger', 'driver', 'admin']);
            // The ONLY place suspension is stored (for every role, drivers included).
            $table->enum('account_status', ['active', 'suspended'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
