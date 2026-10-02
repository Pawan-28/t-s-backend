<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * MySQL/MariaDB DATETIME columns hold the wall clock of the APP time zone for years 1000-9999 only (PostgreSQL
 * timestamptz accepted a far wider range). A client-supplied instant outside it - e.g. 9999-12-31T23:59:59Z, which is
 * already year 10000 in Asia/Kolkata - would be a strict-mode insert error (HTTP 500), so it is refused up front.
 */
final class DateRange
{
    public const MIN_YEAR = 1000;

    public const MAX_YEAR = 9999;

    public static function fits(CarbonInterface $moment): bool
    {
        $year = (int) $moment->copy()->setTimezone(config('app.timezone'))->format('Y');

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    public const MESSAGE = 'Date/time is outside the supported range (years 1000 to 9999).';
}
