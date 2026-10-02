<?php

namespace App\Support;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;

/**
 * Verifies Django's `pbkdf2_sha256$<iterations>$<salt>$<base64 hash>` hashes
 * (imported unchanged from the Django database) and falls back to bcrypt for
 * everything else. needsRehash() is true for every legacy hash, so the first
 * successful login transparently upgrades the stored hash to bcrypt.
 */
class DjangoPbkdf2Hasher implements Hasher
{
    public function __construct(private BcryptHasher $bcrypt) {}

    public function info($hashedValue): array
    {
        return self::isDjango($hashedValue)
            ? ['algo' => 'pbkdf2_sha256', 'algoName' => 'pbkdf2_sha256', 'options' => []]
            : $this->bcrypt->info($hashedValue);
    }

    public function make($value, array $options = []): string
    {
        return $this->bcrypt->make($value, $options);
    }

    public function check($value, $hashedValue, array $options = []): bool
    {
        if ($hashedValue === null || $hashedValue === '' || $value === null) {
            return false;
        }
        if (! self::isDjango($hashedValue)) {
            return $this->bcrypt->check($value, $hashedValue, $options);
        }
        $parts = explode('$', $hashedValue, 4);
        if (count($parts) !== 4) {
            return false;
        }
        [, $iterations, $salt, $expected] = $parts;
        $iterations = (int) $iterations;
        if ($iterations < 1) {
            return false;
        }
        $raw = hash_pbkdf2('sha256', (string) $value, $salt, $iterations, 0, true);

        return hash_equals($expected, base64_encode($raw));
    }

    public function needsRehash($hashedValue, array $options = []): bool
    {
        return self::isDjango($hashedValue) || $this->bcrypt->needsRehash($hashedValue, $options);
    }

    public static function isDjango(?string $hash): bool
    {
        return is_string($hash) && str_starts_with($hash, 'pbkdf2_sha256$');
    }

    /** Test/helper: build a Django-format hash. */
    public static function makeDjango(string $password, string $salt = 'saltsalt', int $iterations = 1000): string
    {
        return 'pbkdf2_sha256$'.$iterations.'$'.$salt.'$'.base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, 0, true));
    }
}
