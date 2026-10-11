<?php

namespace App\Console\Commands;

use App\Services\RideService;
use Illuminate\Console\Command;

/**
 * php artisan rides:expire, every minute (routes/console.php).
 * Requests no driver accepted in time → cancelled by the system, reason no_driver (§ E.5 #7).
 * The passenger's own poll does the same for their ride; this catches rides nobody is polling.
 */
class ExpireRideRequests extends Command
{
    protected $signature = 'rides:expire';

    protected $description = 'Cancel ride requests that no driver accepted before they expired';

    public function handle(RideService $rides): int
    {
        $this->info($rides->expireAllDue().' ride request(s) expired.');

        return self::SUCCESS;
    }
}
