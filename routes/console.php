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
