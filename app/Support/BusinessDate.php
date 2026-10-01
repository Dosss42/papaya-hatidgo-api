<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Today" on the Philippine calendar (config app.business_timezone), for every rule about a
 * DATE: document expiry, "the expiry date can't be in the past", "expires in N days".
 * Timestamps (created_at, submitted_at…) are not affected; they stay UTC.
 *
 * Dates are compared as YYYY-MM-DD strings, so the time-of-day and the timezone a date
 * object happens to carry can never shift the answer.
 */
final class BusinessDate
{
    /** e.g. "2026-10-02" (already Oct 2 in Manila at 16:00 UTC on Oct 1). */
    public static function todayString(): string
    {
        return CarbonImmutable::now(config('app.business_timezone'))->toDateString();
    }

    /** Today's business date as a date-only value (midnight), for date arithmetic. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::todayString());
    }

    /** True when the date is BEFORE today (a document is valid through its expiry date). */
    public static function isPast(?CarbonInterface $date): bool
    {
        return $date !== null && $date->toDateString() < self::todayString();
    }

    /** Whole days from today to the date (0 = expires today, negative = already past). */
    public static function daysUntil(CarbonInterface $date): int
    {
        return (int) self::today()->diffInDays(CarbonImmutable::parse($date->toDateString()), false);
    }

    /** A moment (stored in UTC) as the date people see in the Philippines, e.g. "Nov 1, 2026" (Phase 8 messages). */
    public static function format(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone(config('app.business_timezone'))->format('M j, Y');
    }
}
