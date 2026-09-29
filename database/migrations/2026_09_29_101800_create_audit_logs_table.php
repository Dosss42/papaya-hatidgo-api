<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of important actions (approvals, suspensions, fare changes, manual
     * subscription activation...). The target can be any table, so it is stored as
     * type + id (polymorphic) without a foreign key: an audit entry must survive even if its
     * target changes. old/new values are JSON snapshots: history to read, not data to join.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Who did it; NULL = the system (e.g. the scheduler expiring a document).
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);                // e.g. driver_document.approved, fare.changed
            $table->string('auditable_type', 80);        // e.g. driver_document
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable(); // 45 chars fits IPv6
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
