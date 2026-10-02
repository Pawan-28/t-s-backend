<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** DRF SearchFilter / OrderingFilter / DjangoFilterBackend equivalents. */
class DrfQuery
{
    /**
     * Columns that hold NULL in real rows (drafts have no published_at ...). MySQL/MariaDB sort NULL as the
     * SMALLEST value (first ascending, last descending), PostgreSQL/Django as the LARGEST (last ascending,
     * first descending); orderPg() restores the PostgreSQL placement for these columns.
     */
    public const NULLABLE_COLUMNS = [
        'published_at', 'scheduled_publish_at', 'subscribers_notified_at', 'executed_at', 'completed_at',
        'expires_at', 'started_at', 'last_login', 'phone_verified_at', 'last_used_at',
    ];

    /**
     * orderBy() with PostgreSQL NULL placement (NULLS LAST for asc, NULLS FIRST for desc) for the nullable
     * columns above; a plain orderBy() for everything else.
     *
     * @param  Builder|QueryBuilder  $query
     */
    public static function orderPg($query, string $column, string $direction = 'asc')
    {
        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $bare = strtolower(substr(strrchr('.'.$column, '.'), 1));
        if (in_array($bare, self::NULLABLE_COLUMNS, true)) {
            // (col IS NULL) is 0/1: ASC puts NULL last, DESC puts NULL first.
            $query->orderByRaw(DB::getQueryGrammar()->wrap($column).' IS NULL '.strtoupper($dir));
        }

        return $query->orderBy($column, $dir);
    }

    /**
     * ?search=a b,c : every term must match at least one field (icontains).
     *
     * $publicOnlyFields are matched only on rows whose access_level is PUBLIC (used to keep restricted
     * article bodies from being probed through the search box by callers who may not read them).
     */
    public static function search(Builder $query, Request $request, array $fields, string $param = 'search', array $publicOnlyFields = []): Builder
    {
        $raw = $request->query($param);
        if (! is_string($raw) || trim($raw) === '') {
            return $query;
        }
        $terms = preg_split('/\s+/', trim(str_replace(',', ' ', $raw))) ?: [];
        foreach (array_slice($terms, 0, 10) as $term) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($term, 0, 100)).'%';
            $query->where(function (Builder $q) use ($fields, $publicOnlyFields, $like) {
                foreach ($fields as $field) {
                    $q->orWhereRaw('LOWER('.$field.') LIKE LOWER(?)', [$like]);
                }
                foreach ($publicOnlyFields as $field) {
                    $q->orWhereRaw("(articles.access_level = 'PUBLIC' AND LOWER(".$field.') LIKE LOWER(?))', [$like]);
                }
            });
        }

        return $query;
    }

    /**
     * ?ordering=-created_at,name. Unknown fields are silently ignored; when nothing
     * valid remains the $default (list of [column, dir]) is used.
     *
     * @param  array<string,string>  $allowed  param name => column
     * @param  list<array{0:string,1:string}>  $default
     */
    public static function order(Builder $query, Request $request, array $allowed, array $default): Builder
    {
        $applied = [];
        $raw = $request->query('ordering');
        if (is_string($raw)) {
            foreach (explode(',', $raw) as $term) {
                $term = trim($term);
                $dir = str_starts_with($term, '-') ? 'desc' : 'asc';
                $name = ltrim($term, '-');
                if (isset($allowed[$name])) {
                    $applied[] = [$allowed[$name], $dir];
                }
            }
        }
        foreach ($applied ?: $default as [$col, $dir]) {
            self::orderPg($query, $col, $dir);
        }

        return $query;
    }

    /** django-filter ChoiceFilter: '' ignored, otherwise must be one of $choices (400 like DRF). */
    public static function choiceParam(Request $request, string $param, array $choices): ?string
    {
        $v = $request->query($param);
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v) || ! in_array($v, $choices, true)) {
            throw ValidationException::withMessages([$param => ['Select a valid choice. '.(is_string($v) ? $v : 'That choice').' is not one of the available choices.']]);
        }

        return $v;
    }
}
