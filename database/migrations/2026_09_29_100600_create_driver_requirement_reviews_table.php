<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The permanent history of every review of every document. The single place where
     * "who reviewed it, when, and why" is recorded. Rows are only ever added.
     */
    public function up(): void
    {
        Schema::create('driver_requirement_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_document_id')->constrained()->restrictOnDelete();
            // The admin who reviewed it; NULL only when the system acted (automatic expiry).
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('action', ['approved', 'rejected', 'resubmission_requested', 'expired_by_system']);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Business rules the database itself enforces (MySQL 8 CHECK constraints):
        // 1. Rejecting or requesting resubmission requires a reason (driver-requirements brief).
        // 2. Only the system may act without a reviewer, and the system only expires documents.
        DB::statement("ALTER TABLE driver_requirement_reviews
            ADD CONSTRAINT chk_review_reason
                CHECK (action IN ('approved', 'expired_by_system') OR (reason IS NOT NULL AND reason <> '')),
            ADD CONSTRAINT chk_review_actor
                CHECK ((action = 'expired_by_system') = (reviewer_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_requirement_reviews');
    }
};
