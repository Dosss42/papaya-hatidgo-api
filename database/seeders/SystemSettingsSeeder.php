<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Admin-adjustable configuration. Safe to re-run: updates by key, never duplicates.
 * Values marked PLACEHOLDER are open decisions (PRODUCT.md) and must be confirmed.
 */
class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Ride types (admin can switch each off)
            ['ride.one_way_enabled', '1', 'bool', 'Allow One-way rides'],
            ['ride.two_way_enabled', '1', 'bool', 'Allow Balikan (two-way) rides'],

            // Matching (phase-0 § E.5, driver-home brief)
            ['matching.radius_km', '3', 'decimal', 'Max distance from pickup to an eligible driver'],
            ['matching.max_offers', '5', 'int', 'How many nearest eligible drivers receive a request'],
            ['matching.request_timeout_seconds', '120', 'int', 'Unaccepted requests are cancelled after this'],
            ['matching.stale_online_minutes', '3', 'int', 'A driver counts as online only if located within this many minutes'],
            ['ride.min_distance_m', '100', 'int', 'Minimum pickup-to-destination distance (booking brief)'],

            // Subscriptions (N18: effective status is computed with this)
            ['subscription.grace_days', '0', 'int', 'Days after ends_at before a subscription counts as expired'],

            // Service area: PLACEHOLDER circle until the exact town is confirmed (open decision)
            ['service_area.center_lat', '15.3500000', 'decimal', 'PLACEHOLDER: service area center latitude'],
            ['service_area.center_lng', '121.0500000', 'decimal', 'PLACEHOLDER: service area center longitude'],
            ['service_area.radius_km', '8', 'decimal', 'PLACEHOLDER: service area radius'],
        ];

        foreach ($settings as [$key, $value, $type, $description]) {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $type, 'description' => $description],
            );
        }
    }
}
