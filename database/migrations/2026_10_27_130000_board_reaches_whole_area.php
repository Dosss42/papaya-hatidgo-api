<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The student's test in town: a driver at the edge of Papaya (8 km from a booking in the center)
 * saw nothing with the 5 km board. Now the board's default is 0 = the whole service area: every
 * online driver in town sees every booking, nearest first. The admin may still set a distance.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('key', 'matching.board_radius_km')->where('value', '5')->update([
            'value' => '0',
            'description' => 'Drivers see open requests within this distance and may choose one (the request board); 0 = the whole service area',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'matching.board_radius_km')->where('value', '0')->update(['value' => '5']);
    }
};
