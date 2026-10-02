<?php

namespace App\Services\Import\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Would the target database treat these two strings as equal?" for one target column.
 *
 * utf8mb4_unicode_ci makes UNIQUE indexes case-, accent- and trailing-space-insensitive (PAD SPACE), so
 * 'José@x.com', 'jose@x.com' and 'JOSE@X.COM ' collide although PHP/PostgreSQL see three different values.
 * The key of a value is the server's own WEIGHT_STRING() under the column's real collation (after removing the
 * trailing spaces a PAD SPACE collation ignores), so this is exactly the equality the unique index applies.
 * If the server cannot compute it the key falls back to a PHP approximation (case/accent fold), which is
 * stricter for Latin text and never weaker than plain lower-casing.
 */
class Collation
{
    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<string, string> value => key */
    private array $cache = [];

    private bool $serverKeys = true;

    private bool $noPad;

    private function __construct(private string $table, private string $column, private string $collation)
    {
        $this->noPad = str_contains($collation, 'nopad') || str_contains($collation, '0900');
    }

    public static function flush(): void
    {
        self::$instances = [];
    }

    public static function forColumn(string $table, string $column): self
    {
        $k = $table.'.'.$column;
        if (! isset(self::$instances[$k])) {
            $name = TargetSchema::columns($table)[$column]['collation'] ?? null;
            $name = $name && preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : 'utf8mb4_unicode_ci';
            self::$instances[$k] = new self($table, $column, $name);
        }

        return self::$instances[$k];
    }

    public function collation(): string
    {
        return $this->collation;
    }

    private function pad(string $v): string
    {
        return $this->noPad ? $v : rtrim($v, ' ');
    }

    /** Computes and caches the keys of many values with few round trips. @param iterable<mixed> $values */
    public function prime(iterable $values): void
    {
        $todo = [];
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $s = $this->pad((string) $v);
            if (! isset($this->cache[$s])) {
                $todo[$s] = true;
            }
        }
        if (! $todo || ! $this->serverKeys) {
            return;
        }
        foreach (array_chunk(array_keys($todo), 400) as $chunk) {
            if (! $this->fetch($chunk)) {
                return;
            }
        }
    }

    /** @param list<string> $strings already PAD-trimmed */
    private function fetch(array $strings): bool
    {
        $sel = [];
        foreach ($strings as $i => $_) {
            $sel[] = $i === 0 ? 'SELECT 0 AS i, ? AS v' : "SELECT {$i}, ?";
        }
        $sql = 'SELECT t.i AS i, HEX(WEIGHT_STRING(CONVERT(t.v USING utf8mb4) COLLATE '.$this->collation.')) AS k FROM ('.implode(' UNION ALL ', $sel).') t';
        try {
            $rows = DB::select($sql, $strings);
        } catch (\Throwable) {
            $this->serverKeys = false;

            return false;
        }
        foreach ($rows as $r) {
            $this->cache[$strings[(int) $r->i]] = 'w:'.(string) $r->k;
        }

        return true;
    }

    public function key(?string $value): string
    {
        $s = $this->pad((string) $value);
        if (isset($this->cache[$s])) {
            return $this->cache[$s];
        }
        if ($this->serverKeys && $this->fetch([$s]) && isset($this->cache[$s])) {
            return $this->cache[$s];
        }

        return $this->cache[$s] = 'p:'.self::approximate($s);
    }

    /** Case / accent / compatibility fold (PHP-only fallback). */
    public static function approximate(string $s): string
    {
        static $tr = null;
        if ($tr === null) {
            $tr = class_exists(\Transliterator::class) ? (\Transliterator::create('NFKD; [:Nonspacing Mark:] Remove; Lower(); NFKC') ?: false) : false;
        }
        $s = str_replace(['ß', 'ẞ', 'æ', 'Æ', 'œ', 'Œ'], ['ss', 'ss', 'ae', 'ae', 'oe', 'oe'], $s);
        $out = $tr ? $tr->transliterate($s) : null;

        return $out === null || $out === false ? mb_strtolower($s) : $out;
    }
}
