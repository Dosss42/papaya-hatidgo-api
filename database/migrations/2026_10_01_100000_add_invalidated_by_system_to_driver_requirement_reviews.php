<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 (step 7.2): a second SYSTEM action in the review history.
 *
 * When a driver changes the plate number of a tricycle, its current OR/CR and MTOP no longer
 * prove anything (they show the old plate). The system marks them "resubmission required" and
 * records WHY, so the driver never sees "needs fixing" without a reason.
 *
 * Before: only 'expired_by_system' could have no reviewer. After: 'invalidated_by_system' too.
 * The reason rule still applies: 'invalidated_by_system' must carry a reason.
 * (The original migration is never edited: databases that already ran it would not run it again.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL won't change a column that a CHECK constraint uses, so: drop the checks,
        // widen the enum, put the checks back (the actor rule now names both system actions).
        DB::statement('ALTER TABLE driver_requirement_reviews DROP CHECK chk_review_reason, DROP CHECK chk_review_actor');

        DB::statement("ALTER TABLE driver_requirement_reviews MODIFY action
            ENUM('approved', 'rejected', 'resubmission_requested', 'expired_by_system', 'invalidated_by_system') NOT NULL");

        DB::statement("ALTER TABLE driver_requirement_reviews
            ADD CONSTRAINT chk_review_reason
                CHECK (action IN ('approved', 'expired_by_system') OR (reason IS NOT NULL AND reason <> '')),
            ADD CONSTRAINT chk_review_actor
                CHECK ((action IN ('expired_by_system', 'invalidated_by_system')) = (reviewer_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM driver_requirement_reviews WHERE action = 'invalidated_by_system'");
        DB::statement('ALTER TABLE driver_requirement_reviews DROP CHECK chk_review_reason, DROP CHECK chk_review_actor');

        DB::statement("ALTER TABLE driver_requirement_reviews MODIFY action
            ENUM('approved', 'rejected', 'resubmission_requested', 'expired_by_system') NOT NULL");

        DB::statement("ALTER TABLE driver_requirement_reviews
            ADD CONSTRAINT chk_review_reason
                CHECK (action IN ('approved', 'expired_by_system') OR (reason IS NOT NULL AND reason <> '')),
            ADD CONSTRAINT chk_review_actor
                CHECK ((action = 'expired_by_system') = (reviewer_id IS NULL))");
    }
};
