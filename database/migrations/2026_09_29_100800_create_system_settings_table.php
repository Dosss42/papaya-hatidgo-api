<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-adjustable configuration (ride types on/off, matching radius, timeouts, service area).
     * A key/value table is a deliberate choice for CONFIGURATION, not business data: settings are
     * few, known by the code, and added without schema changes. The trade-off (values stored as
     * text) is handled by the `type` column and validated in SettingsService.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value');
            $table->enum('type', ['int', 'decimal', 'bool', 'string']);
            $table->string('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
