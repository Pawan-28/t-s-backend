<?php

namespace App\Http\Controllers\Api\Concerns;

/** Small helpers reproducing DRF field coercion for multipart/JSON payloads. */
trait ParsesDrfInput
{
    /** DRF BooleanField: returns true/false, or null when the value is not a valid boolean. */
    protected function drfBool(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v == 1 ? true : ($v == 0 ? false : null);
        }
        if (! is_string($v)) {
            return null;
        }

        return match (strtolower(trim($v))) {
            't', 'y', 'yes', 'true', 'on', '1', '1.0' => true,
            'f', 'n', 'no', 'false', 'off', '0', '0.0' => false,
            default => null,
        };
    }

    /** DRF PositiveIntegerField parsing; returns [value|null, error|null]. */
    protected function drfPositiveInt(mixed $v): array
    {
        if (is_bool($v) || (! is_int($v) && ! (is_string($v) && preg_match('/^\s*[+-]?\d+\s*$/', $v)))) {
            return [null, 'A valid integer is required.'];
        }
        $n = (int) $v;
        if ($n < 0) {
            return [null, 'Ensure this value is greater than or equal to 0.'];
        }
        if ($n > 2147483647) {
            return [null, 'Ensure this value is less than or equal to 2147483647.'];
        }

        return [$n, null];
    }

    /** DRF CharField(max_length, allow_blank): returns [string|null, error|null]. */
    protected function drfChar(mixed $v, int $max, bool $allowBlank = true): array
    {
        if ($v === null) {
            return $allowBlank ? ['', null] : [null, 'This field may not be null.'];
        }
        if (is_array($v) || is_object($v) || is_bool($v)) {
            return [null, 'Not a valid string.'];
        }
        $s = trim((string) $v);
        if ($s === '' && ! $allowBlank) {
            return [null, 'This field may not be blank.'];
        }
        if (mb_strlen($s) > $max) {
            return [null, "Ensure this field has no more than {$max} characters."];
        }

        return [$s, null];
    }
}
