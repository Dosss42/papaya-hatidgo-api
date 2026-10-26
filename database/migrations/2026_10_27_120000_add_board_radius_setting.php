<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The request board (the student's hybrid dispatch, after Phase 13): drivers see open requests
 * within this many km and choose one. Added here and in SystemSettingsSeeder, so an existing
 * database gets it without re-running the seeder (which would reset the admin's other settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'matching.board_radius_km',
            'value' => '5',
            'type' => 'decimal',
            'description' => 'Drivers see open requests within this distance and may choose one (the request board)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'matching.board_radius_km')->delete();
    }
};
