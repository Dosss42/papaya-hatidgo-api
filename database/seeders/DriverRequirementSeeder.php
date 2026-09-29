<?php

namespace Database\Seeders;

use App\Models\DriverRequirement;
use Illuminate\Database\Seeder;

/**
 * The four documents every driver needs (decision 14): all required, critical and expiring.
 * Safe to re-run: updates by code. The admin can change them later.
 */
class DriverRequirementSeeder extends Seeder
{
    public function run(): void
    {
        $requirements = [
            [
                'code' => 'drivers_license',
                'name' => "Driver's license",
                'description' => 'Harap at likod ng driver\'s license mo.',
                'applies_to' => 'driver',
                'max_files' => 2, // front + back
            ],
            [
                'code' => 'or_cr',
                'name' => 'OR/CR ng tricycle',
                'description' => 'Official Receipt at Certificate of Registration mula sa LTO.',
                'applies_to' => 'vehicle',
                'max_files' => 1,
            ],
            [
                'code' => 'mtop_permit',
                'name' => 'Franchise / MTOP permit',
                'description' => 'Motorized Tricycle Operator\'s Permit (prangkisa) mula sa munisipyo.',
                'applies_to' => 'vehicle',
                'max_files' => 1,
            ],
            [
                'code' => 'clearance',
                'name' => 'Clearance',
                'description' => 'Barangay, police, o NBI clearance.',
                'applies_to' => 'driver',
                'max_files' => 1,
            ],
        ];

        foreach ($requirements as $sort => $requirement) {
            DriverRequirement::updateOrCreate(
                ['code' => $requirement['code']],
                $requirement + [
                    'is_required' => true,
                    'is_critical' => true,
                    'requires_expiry' => true,
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
        }
    }
}
