<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every common way of typing a PH mobile number becomes the one stored format. */
class PhoneNumberTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function test_it_normalizes_common_formats(string $typed): void
    {
        $this->assertSame('+639171234567', PhoneNumber::normalize($typed));
    }

    public static function validNumbers(): array
    {
        return [
            'local 09' => ['09171234567'],
            'local with spaces' => ['0917 123 4567'],
            'no leading zero' => ['9171234567'],
            'country code' => ['639171234567'],
            'E.164' => ['+639171234567'],
            'E.164 with dashes' => ['+63 917-123-4567'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_rejects_non_mobile_numbers(?string $typed): void
    {
        $this->assertNull(PhoneNumber::normalize($typed));
    }

    public static function invalidNumbers(): array
    {
        return [
            'too short' => ['0917123'],
            'landline' => ['0441234567'],
            'letters' => ['abc'],
            'empty' => [''],
            'null' => [null],
        ];
    }
}
