<?php

/*
 * The seeded requirements' names and descriptions in Taglish, keyed by their stable `code`
 * (driver_requirements.code). English: lang/en/requirements.php.
 * A requirement the admin adds later has no entry here: the app then shows the name and
 * description exactly as the admin typed them in the database.
 */

return [
    'drivers_license' => [
        'name' => "Driver's license",
        'description' => "Harap at likod ng driver's license mo.",
    ],
    'or_cr' => [
        'name' => 'OR/CR ng tricycle',
        'description' => 'Official Receipt at Certificate of Registration mula sa LTO.',
    ],
    'mtop_permit' => [
        'name' => 'Franchise / MTOP permit',
        'description' => "Motorized Tricycle Operator's Permit (prangkisa) mula sa munisipyo.",
    ],
    'clearance' => [
        'name' => 'Clearance',
        'description' => 'Barangay, police, o NBI clearance.',
    ],
];
