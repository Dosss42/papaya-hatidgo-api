<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The uploaded file(s) of one document (e.g. license front + back). Split from
     * driver_documents because one document has many files (1:N). Files are never edited,
     * only added, so there is no updated_at.
     */
    public function up(): void
    {
        Schema::create('driver_document_files', function (Blueprint $table) {
            $table->id();
            // Composition: a file cannot exist without its document.
            $table->foreignId('driver_document_id')->constrained()->cascadeOnDelete();
            // Path on Laravel's PRIVATE disk (never under public/). Random file names.
            $table->string('file_path')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size'); // bytes
            $table->enum('side', ['front', 'back', 'page'])->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['driver_document_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_document_files');
    }
};
