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

// Rides (Phase 9): unaccepted requests past their 2 minutes → "no driver" (the passenger's poll
// also does this; the schedule catches rides nobody is polling), and drivers whose last location
// is older than 3 minutes → offline (the stale-online rule).
Schedule::command('rides:expire')->everyMinute()->withoutOverlapping();
Schedule::command('drivers:offline-stale')->everyMinute()->withoutOverlapping();

// Notifications (Phase 13): papers expiring / expired, subscriptions ending / ended. Hourly in the
// daytime only (nobody is woken at midnight by a reminder); each reminder is sent once.
Schedule::command('notifications:reminders')
    ->hourly()
    ->between('7:00', '20:00')
    ->timezone(config('app.business_timezone'))
    ->withoutOverlapping();
