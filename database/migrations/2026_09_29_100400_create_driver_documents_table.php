<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One submission of one requirement. A resubmission or renewal is a NEW row, so no
     * approval or rejection is ever overwritten. Who reviewed it and when lives only in
     * driver_requirement_reviews (not duplicated here). Files live in driver_document_files.
     */
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_requirement_id')->constrained()->restrictOnDelete();
            // Set only for vehicle requirements (OR/CR, MTOP); NULL for driver requirements.
            $table->unsignedBigInteger('vehicle_id')->nullable();

            $table->string('document_number', 50)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'resubmission_required'])
                ->default('pending');

            // The document that currently counts for this driver + requirement (+ vehicle).
            // During a renewal the old approved one stays current until the new one is approved.
            $table->boolean('is_current')->default(false);

            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            // A vehicle document must be about one of THIS driver's own vehicles.
            $table->foreign(['vehicle_id', 'driver_id'])
                ->references(['id', 'driver_id'])
                ->on('vehicles')
                ->restrictOnDelete();

            // "At most one current document per driver + requirement + vehicle", enforced by MySQL:
            // current_key has a value only while is_current = 1, and a UNIQUE index refuses
            // a second current row. Non-current rows are NULL, and NULLs never collide.
            $table->string('current_key', 64)
                ->storedAs("IF(is_current, CONCAT(driver_id, '-', driver_requirement_id, '-', IFNULL(vehicle_id, 0)), NULL)")
                ->nullable()
                ->unique();

            // The daily scheduler looks for approved documents about to expire.
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
