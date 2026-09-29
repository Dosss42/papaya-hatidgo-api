<?php

namespace App\Services;

use App\Models\SystemSetting;

/** Typed access to system_settings (the admin-adjustable configuration). */
class SettingsService
{
    public function get(string $key, int|float|bool|string|null $default = null): int|float|bool|string|null
    {
        $setting = SystemSetting::where('key', $key)->first();

        return $setting ? $setting->typedValue() : $default;
    }
}
