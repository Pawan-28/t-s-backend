<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic for DECIMAL(10,2) amounts. Amounts are decimal STRINGS ("499.00") or integer
 * minor units (paise); float multiplication is never used, so 19.99 * 100 can never become 1998.9999.
 * (bcmath is not guaranteed on shared hosting, hence plain integer math on the digits.)
 */
final class Money
{
    /** "499.00" | "499.5" | 499 | 19.99 -> 49900 | 49950 | 49900 | 1999 (paise). Rejects more than 2 decimals. */
    public static function toMinorUnits(string|int|float $amount): int
    {
        $s = match (true) {
            is_int($amount) => (string) $amount,
            // A float is a lossy representation already: go through its shortest round-trip decimal text.
            is_float($amount) => (string) json_encode($amount), // shortest round-trip text (serialize_precision=-1)
            default => trim($amount),
        };
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,2})0*)?$/', $s, $m)) {
            throw new InvalidArgumentException('Not an amount with at most 2 decimals: '.$s);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 49900 -> "499.00" (the DRF DecimalField / DECIMAL(10,2) text form). */
    public static function fromMinorUnits(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }

    /** Razorpay-reported paise (int, or an all-digit string) equals the stored decimal amount. */
    public static function paiseEquals(mixed $reportedPaise, string|int|float $storedAmount): bool
    {
        if (is_string($reportedPaise) && ctype_digit($reportedPaise)) {
            $reportedPaise = (int) $reportedPaise;
        }
        if (! is_int($reportedPaise)) {
            return false;
        }

        try {
            return $reportedPaise === self::toMinorUnits($storedAmount);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
