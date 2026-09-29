<?php

namespace App\Support;

/**
 * Philippine mobile numbers in ONE stored format (E.164: +639XXXXXXXXX).
 * People type the same number many ways: 0917 123 4567, 09171234567, 639171234567,
 * +63 917-123-4567. Normalizing before saving/checking makes "unique phone" actually work.
 */
class PhoneNumber
{
    /** Returns +639XXXXXXXXX, or null when it isn't a PH mobile number. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input); // keep digits only

        $national = match (true) {
            str_starts_with($digits, '639') && strlen($digits) === 12 => substr($digits, 2), // 639171234567
            str_starts_with($digits, '09') && strlen($digits) === 11 => substr($digits, 1),  // 09171234567
            str_starts_with($digits, '9') && strlen($digits) === 10 => $digits,               // 9171234567
            default => null,
        };

        return $national === null ? null : '+63'.$national;
    }
}
