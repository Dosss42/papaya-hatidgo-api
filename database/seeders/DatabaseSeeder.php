<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Configuration the system needs in EVERY environment (safe to re-run).
        $this->call([
            SystemSettingsSeeder::class,
            FareSettingsSeeder::class,
            SubscriptionPlanSeeder::class,
            DriverRequirementSeeder::class,
        ]);

        // Sample accounts with known passwords: local development and automated tests ONLY.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
