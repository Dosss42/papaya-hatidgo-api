<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road routing (the student's request after Phase 13): the road the fare was measured on.
 * - route_points: the line along the roads, [[lat, lng], …], thinned to a few hundred points,
 *   so both phones draw the same road the fare used.
 * - distance_by_road: false when the routing service couldn't answer and the distance is the
 *   straight line × the detour factor (RoutingService::FALLBACK), so it's never mistaken for a road.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->json('route_points')->nullable()->after('return_distance_km');
            $table->boolean('distance_by_road')->default(false)->after('route_points');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['route_points', 'distance_by_road']);
        });
    }
};
