<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

/**
 * php artisan notifications:reminders, hourly from 7 AM to 8 PM Philippine time (routes/console.php).
 * Papers expiring in 30 / 7 days or expired; subscriptions ending in 3 days or ended. Each once.
 */
class SendReminders extends Command
{
    protected $signature = 'notifications:reminders';

    protected $description = 'Send paper and subscription reminders that are due (each one only once)';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->sendDue();
        $this->info(collect($sent)->map(fn (int $n, string $what) => "{$what}: {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
