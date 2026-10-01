<?php

namespace App\Support;

/**
 * Plate numbers are typed in many ways ("abc 1234", "ABC-1234", "ABC1234"); they are the same
 * plate. Stored in ONE form, uppercase letters and digits only, so the UNIQUE index on
 * vehicles.plate_number really means "one tricycle per plate". (Same idea as PhoneNumber.)
 */
final class PlateNumber
{
    public static function normalize(?string $plate): ?string
    {
        if ($plate === null) {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[\s\-]+/', '', $plate));

        return $clean === '' ? null : $clean;
    }
}
