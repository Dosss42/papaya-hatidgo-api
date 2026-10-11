<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 (active-ride brief § 8): when a Balikan passenger taps "Pabalik na ako", the waiting
 * driver must find out. Stored on the ride, so the driver's normal polling shows it: the ride
 * flow never depends on push notifications (phase-0 § J). It changes no status. It also limits
 * the button to once per 5 minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->timestamp('passenger_returning_at')->nullable()->after('return_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('passenger_returning_at');
        });
    }
};
