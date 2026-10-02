<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ported from Django core.utils.unique_slugify: slug of the value (max 220),
 * "item" fallback for values with no ASCII letters, "-2", "-3" ... suffix on
 * collision, own row excluded, optional scoping (e.g. subcategory per category).
 */
class Slug
{
    public static function unique(string $modelClass, string $value, int $maxLength = 220, ?Model $ignore = null, array $scope = [], string $column = 'slug'): string
    {
        $base = rtrim(mb_substr(Str::slug($value), 0, $maxLength), '-');
        $base = $base !== '' ? $base : 'item';

        $candidate = $base;
        $n = 2;
        while (self::exists($modelClass, $column, $candidate, $ignore, $scope)) {
            $suffix = '-'.$n;
            $candidate = rtrim(mb_substr($base, 0, $maxLength - strlen($suffix)), '-').$suffix;
            $n++;
        }

        return $candidate;
    }

    private static function exists(string $modelClass, string $column, string $slug, ?Model $ignore, array $scope): bool
    {
        $q = $modelClass::query()->where($column, $slug);
        foreach ($scope as $col => $val) {
            $q->where($col, $val);
        }
        if ($ignore && $ignore->exists) {
            $q->whereKeyNot($ignore->getKey());
        }

        return $q->exists();
    }
}
