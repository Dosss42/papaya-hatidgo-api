<?php

namespace App\Console\Commands;

use App\Services\DocumentExpiryService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;

/**
 * php artisan documents:expire
 * Runs every day at 00:05 Philippine time (routes/console.php); can also be run by hand.
 */
class ExpireDocuments extends Command
{
    protected $signature = 'documents:expire';

    protected $description = 'Mark approved driver documents past their expiry date as expired, then recalculate those drivers';

    public function handle(DocumentExpiryService $expiry): int
    {
        $result = $expiry->expireOverdue();

        $this->info(sprintf(
            'Today (%s, %s): %d document(s) expired, %d driver(s) recalculated.',
            BusinessDate::todayString(),
            config('app.business_timezone'),
            $result['documents'],
            $result['drivers'],
        ));

        return self::SUCCESS;
    }
}
