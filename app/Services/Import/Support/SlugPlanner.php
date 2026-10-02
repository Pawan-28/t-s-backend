<?php

namespace App\Services\Import\Support;

use Illuminate\Support\Str;

/**
 * Deterministic slug de-duplication. Case-insensitive; the highest-priority row
 * (then lowest id) keeps its slug, later rows get "-2", "-3", ... (truncated to
 * the column limit). Empty slugs are generated from the name/title.
 */
class SlugPlanner
{
    /**
     * @param  array<int, array{slug: ?string, name: ?string, priority?: int}>  $rows  keyed by source id
     * @param  array<string, true>  $externalTaken  KEYS (see $key) of slugs already held by other (non-source) rows
     * @param  (callable(string): string)|null  $key  equality key of a slug; default = lower-case. The importer passes the target
     *                                                column's collation key (case/accent/trailing-space insensitive).
     * @return array<int, array{slug: string, original: ?string, generated: bool, changed: bool}>
     */
    public static function plan(array $rows, int $maxLen, array $externalTaken = [], ?callable $key = null): array
    {
        $key ??= fn (string $s): string => mb_strtolower($s);
        $ids = array_keys($rows);
        usort($ids, function ($a, $b) use ($rows) {
            $pa = $rows[$a]['priority'] ?? 0;
            $pb = $rows[$b]['priority'] ?? 0;

            return $pb <=> $pa ?: $a <=> $b;
        });

        $taken = $externalTaken;
        $out = [];
        // Pass 1: rows whose own slug is non-empty claim it first (by priority),
        // so a generated slug can never steal a real one.
        $pending = [];
        foreach ($ids as $id) {
            $raw = trim((string) ($rows[$id]['slug'] ?? ''));
            if ($raw === '') {
                $pending[] = $id;

                continue;
            }
            $out[$id] = self::claim($raw, $maxLen, $taken, $raw, false, $key);
        }
        foreach ($pending as $id) {
            $base = Str::slug((string) ($rows[$id]['name'] ?? ''));
            $base = $base === '' ? 'item' : $base;
            $out[$id] = self::claim(mb_substr($base, 0, $maxLen), $maxLen, $taken, null, true, $key);
        }
        ksort($out);

        return $out;
    }

    private static function claim(string $base, int $maxLen, array &$taken, ?string $original, bool $generated, callable $key): array
    {
        $base = mb_substr($base, 0, $maxLen);
        $slug = $base;
        $n = 2;
        while (isset($taken[$key($slug)])) {
            $suffix = '-'.$n++;
            $slug = mb_substr($base, 0, $maxLen - strlen($suffix)).$suffix;
        }
        $taken[$key($slug)] = true;

        return ['slug' => $slug, 'original' => $original, 'generated' => $generated, 'changed' => $original !== null && $slug !== $original];
    }
}
