<?php

namespace App\Services\Import\Support;

/**
 * Masks personal data so import reports never contain PII beyond source ids.
 *
 * Emails: "alice@example.com" -> "a***@e***.com". Phones: keep only the last 2 digits.
 */
class Masker
{
    public static function email(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '') {
            return '(blank)';
        }
        if (! str_contains($email, '@')) {
            return mb_substr($email, 0, 1).'***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $dot = strrpos($domain, '.');
        $tld = $dot === false ? '' : substr($domain, $dot);
        $host = $dot === false ? $domain : substr($domain, 0, $dot);

        return mb_substr($local, 0, 1).'***@'.mb_substr($host, 0, 1).'***'.$tld;
    }

    public static function phone(?string $phone): string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '(blank)';
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return '***'.substr($digits, -2);
    }
}
