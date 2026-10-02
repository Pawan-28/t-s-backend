<?php

namespace App\Services\Import\Support;

/**
 * Same-database guard for two connection configs. The drivers are compared FIRST: a PostgreSQL source and a
 * MySQL/MariaDB target can never be the same database. Only configs of the same engine family are compared
 * by (normalised host, port, database).
 */
class DatabaseIdentity
{
    public static function family(?string $driver): string
    {
        $d = strtolower((string) $driver);

        return in_array($d, ['mysql', 'mariadb'], true) ? 'mysql' : $d;
    }

    public static function sameEngineFamily(?string $a, ?string $b): bool
    {
        return self::family($a) === self::family($b);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b connection configs */
    public static function same(array $a, array $b): bool
    {
        if (! self::sameEngineFamily($a['driver'] ?? null, $b['driver'] ?? null)) {
            return false;
        }
        $norm = fn ($h) => in_array(strtolower((string) $h), ['localhost', '::1', '127.0.0.1', ''], true) ? 'local' : strtolower((string) $h);

        return $norm($a['host'] ?? '') === $norm($b['host'] ?? '')
            && (string) ($a['port'] ?? '') === (string) ($b['port'] ?? '')
            && (string) ($a['database'] ?? '') === (string) ($b['database'] ?? '');
    }
}
