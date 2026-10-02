<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DRF-compatible page-number pagination: {count, next, previous, results}
 * with `?page=` (1-based). The frontend depends on this exact envelope.
 */
class Page
{
    /**
     * @param  EloquentBuilder|QueryBuilder|Collection|array  $source
     * @param  callable|null  $map  maps each model/row to its JSON array
     */
    public static function make($source, Request $request, ?callable $map = null, ?int $pageSize = null): array
    {
        $size = max(1, $pageSize ?? (int) config('portal.page_size', 20));
        $page = $request->query('page', 1);
        $page = ctype_digit((string) $page) ? (int) $page : 0;
        if ($page < 1) {
            throw new NotFoundHttpException('Invalid page.');
        }

        if ($source instanceof Collection || is_array($source)) {
            $all = collect($source)->values();
            $count = $all->count();
            $rows = $all->slice(($page - 1) * $size, $size)->values();
        } else {
            $count = (clone $source)->toBase()->getCountForPagination();
            $rows = $source->forPage($page, $size)->get();
        }

        $lastPage = max(1, (int) ceil($count / $size));
        if ($page > $lastPage && $count > 0) {
            throw new NotFoundHttpException('Invalid page.');
        }

        $results = $map ? $rows->map($map)->all() : $rows->all();

        return [
            'count' => $count,
            'next' => $page < $lastPage ? self::url($request, $page + 1) : null,
            'previous' => $page > 1 ? self::url($request, $page - 1 === 1 ? null : $page - 1) : null,
            'results' => $results,
        ];
    }

    private static function url(Request $request, ?int $page): string
    {
        $query = $request->query();
        unset($query['page']);
        if ($page !== null) {
            $query['page'] = $page;
        }
        $base = $request->url();
        // Request::url() drops the trailing slash, but every route of this API is declared WITH one (DRF style) and the
        // slash-less form 404s: keep it so `next`/`previous` are followable (DRF builds them from the requested URL).
        if (str_ends_with((string) parse_url($request->getRequestUri(), PHP_URL_PATH), '/') && ! str_ends_with($base, '/')) {
            $base .= '/';
        }

        return $query ? $base.'?'.http_build_query($query) : $base;
    }
}
