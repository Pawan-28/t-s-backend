<?php

namespace App\Services\Wati;

/**
 * Phone helpers shared by checkout, OTP and WATI (port of Django phone_utils).
 * WATI wants digits only, country code included, no "+", no spaces.
 */
class PhoneNumber
{
    public const INDIA_COUNTRY_CODE = '91';

    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            return self::INDIA_COUNTRY_CODE.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return self::INDIA_COUNTRY_CODE.substr($digits, 1);
        }

        return $digits;
    }

    /** Coarse sanity check (10-15 digits) on an already normalized number. */
    public static function looksValid(string $digits): bool
    {
        $len = strlen($digits);

        return $len >= 10 && $len <= 15;
    }

    /** For logs only: never write a full phone number. */
    public static function mask(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        return str_repeat('*', max(0, strlen($digits) - 3)).substr($digits, -3);
    }
}
