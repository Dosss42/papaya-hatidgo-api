<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduled tasks (run by `php artisan schedule:work` in development,
| or one cron entry calling `php artisan schedule:run` every minute on a server).
*/

// Expired login tokens stop working at once (Sanctum checks expiry on every request);
// this only deletes their rows so the table doesn't grow forever.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Driver documents past their expiry date become "expired" in the database (Phase 7, step 7.5).
// 00:05 PHILIPPINE time: a document is valid through its expiry date, so the new day starts the
// check. withoutOverlapping: if a run is somehow slow, the next one waits instead of doubling up.
Schedule::command('documents:expire')
    ->dailyAt('00:05')
    ->timezone(config('app.business_timezone'))
    ->withoutOverlapping();
