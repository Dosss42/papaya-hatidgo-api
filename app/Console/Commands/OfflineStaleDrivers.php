<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Services\DriverMatchingService;
use Illuminate\Console\Command;

/**
 * php artisan drivers:offline-stale, every minute (routes/console.php).
 * The stale-online rule (driver-home brief § 7): a driver whose phone died or lost signal hasn't
 * sent a location for 3 minutes. They're set offline, so the app never pretends they're online
 * (matching already skips them; this makes the stored state honest too).
 */
class OfflineStaleDrivers extends Command
{
    protected $signature = 'drivers:offline-stale';

    protected $description = 'Set drivers offline when their last location is older than the stale-online limit';

    public function handle(DriverMatchingService $matching): int
    {
        $count = Driver::query()
            ->where('is_online', true)
            ->where(fn ($q) => $q->whereNull('location_updated_at')->orWhere('location_updated_at', '<', $matching->freshSince()))
            ->update(['is_online' => false, 'updated_at' => now()]);
        // "You were taken offline" push: Phase 13.

        $this->info("{$count} stale driver(s) set offline.");

        return self::SUCCESS;
    }
}
