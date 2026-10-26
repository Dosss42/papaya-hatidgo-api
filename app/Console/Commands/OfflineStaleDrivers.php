<?php

namespace App\Console\Commands;

use App\Models\Driver;
use App\Services\DriverMatchingService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * php artisan drivers:offline-stale, every minute (routes/console.php).
 * The stale-online rule (driver-home brief § 7): a driver whose phone died or lost signal hasn't
 * sent a location for 3 minutes. They're set offline, so the app never pretends they're online
 * (matching already skips them; this makes the stored state honest too), and told so (Phase 13).
 */
class OfflineStaleDrivers extends Command
{
    protected $signature = 'drivers:offline-stale';

    protected $description = 'Set drivers offline when their last location is older than the stale-online limit';

    public function handle(DriverMatchingService $matching, NotificationService $notifications): int
    {
        $stale = fn ($q) => $q->where('is_online', true)
            ->where(fn ($q) => $q->whereNull('location_updated_at')->orWhere('location_updated_at', '<', $matching->freshSince()));

        $drivers = Driver::query()->tap($stale)->with('user')->get();
        // The same conditions again in the UPDATE: a driver whose location arrived meanwhile stays online.
        $count = Driver::query()->whereKey($drivers->modelKeys())->tap($stale)->update(['is_online' => false, 'updated_at' => now()]);

        foreach ($drivers as $driver) {
            if (! $driver->refresh()->is_online) {
                $notifications->driverSetOffline($driver, 'stale');
            }
        }

        $this->info("{$count} stale driver(s) set offline.");

        return self::SUCCESS;
    }
}
