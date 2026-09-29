<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * 1 / 6 / 12-month plans for passengers and drivers (decision 17).
 * Longer plans are priced as "months free": 6 months ≈ 5× monthly, 12 months ≈ 10× monthly.
 * SAMPLE PRICES ONLY: real prices are set by the admin. No benefits are promised (open decision).
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $monthly = ['passenger' => 49.00, 'driver' => 199.00]; // sample values
        $labels = ['passenger' => 'Pasahero', 'driver' => 'Driver'];
        $lengths = [1 => 1, 6 => 5, 12 => 10];                 // months => months charged

        $sort = 0;
        foreach ($monthly as $audience => $price) {
            foreach ($lengths as $months => $charged) {
                SubscriptionPlan::updateOrCreate(
                    ['code' => sprintf('%s_%dm', $audience === 'passenger' ? 'pax' : 'drv', $months)],
                    [
                        'name' => sprintf('%s · %d buwan', $labels[$audience], $months),
                        'user_type' => $audience,
                        'duration_months' => $months,
                        'price' => $price * $charged,
                        'currency' => 'PHP',
                        'benefits' => null,
                        'is_active' => true,
                        'sort_order' => $sort++,
                    ],
                );
            }
        }
    }
}
