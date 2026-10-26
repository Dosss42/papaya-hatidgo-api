<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The passenger's cancel window (the student's change after Phase 13): once the driver taps
 * "On my way", the passenger may cancel only if the driver hasn't arrived after N minutes.
 * Added here as well as in SystemSettingsSeeder so a database that already exists gets it
 * WITHOUT re-running the seeder (which would reset the admin's other settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'ride.passenger_cancel_after_minutes',
            'value' => '10',
            'type' => 'int',
            'description' => 'Once the driver is on the way, the passenger may cancel only if the driver has not arrived after this many minutes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'ride.passenger_cancel_after_minutes')->delete();
    }
};
