<?php

namespace Database\Seeders;

use App\Models\FareSetting;
use Illuminate\Database\Seeder;

/**
 * The first fare version, only if none exists (fare versions are insert-only: re-running
 * must never add a second "initial" version). SAMPLE VALUES from the brief, not an
 * official LGU fare matrix.
 */
class FareSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (FareSetting::exists()) {
            return;
        }

        FareSetting::create([
            'base_fare' => 30.00,
            'rate_per_km' => 10.00,
            'minimum_fare' => 30.00,
            'return_rate_multiplier' => 1.00,  // open decision: default 1.0
            'waiting_free_minutes' => 0,
            'waiting_fee_per_minute' => 0.00,  // open decision: waiting fee is ₱0 until decided
            'service_fee' => 0.00,
            'effective_from' => now(),
        ]);
    }
}
