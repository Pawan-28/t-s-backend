<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Services\Articles\ArticleQuery;
use App\Services\Search\SearchQuery;
use App\Support\DrfQuery;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/search/?q=&industry=&category=&subcategory=&tag=&page=&page_size=
 * Public, PUBLISHED-only ranked full-text search (MySQL/MariaDB InnoDB FULLTEXT, see applyTextSearch).
 * The query text is always a bound parameter (never interpolated); user-typed FULLTEXT operators are stripped.
 */
class SearchController extends Controller
{
    private const MAX_QUERY_CHARS = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->query('q');
        $text = is_string($raw) ? mb_substr(trim($raw), 0, self::MAX_QUERY_CHARS) : '';
        $tag = ArticleQuery::param($request, 'tag');
        $hasFilter = collect(['subcategory', 'category', 'industry'])->contains(fn ($k) => ArticleQuery::param($request, $k) !== null)
            || $tag !== null;

        $query = ArticleQuery::published()->with(ArticleResource::relations());
        ArticleQuery::taxonomy($query, $request);
        if ($tag !== null) {
            $query->whereHas('tags', fn ($t) => $t->where('slug', $tag));
        }

        if ($text === '') {
            if ($hasFilter) {
                // Browse a section: newest first, no relevance to compute.
                DrfQuery::orderPg($query, 'articles.published_at', 'desc');
                $query->orderByDesc('articles.id');
            } else {
                $query->whereRaw('1 = 0');
            }
        } else {
            $this->applyTextSearch($query, $text);
        }

        return response()->json(Page::make(
            $query,
            $request,
            fn (Article $a) => (new ArticleResource($a))->resolve($request),
            $this->pageSize($request)
        ));
    }

    /**
     * Ranked full-text match on the article_search_index table (InnoDB FULLTEXT, BOOLEAN MODE).
     * Rows are matched on the combined (title, excerpt, body, taxonomy) index so that "a AND b" works
     * across fields; relevance = combined score + boosts for title (A), excerpt/location (B) and
     * taxonomy/tag names (D) matches. If FULLTEXT cannot answer (only very short words, other
     * scripts the InnoDB parser splits badly, or no hit at all) a substring (LIKE) match on the same
     * fields is used instead, so a search never silently returns nothing for text that is present.
     */
    private function applyTextSearch($query, string $text): void
    {
        $min = max(1, (int) config('portal.search.min_token_size', 3));
        $parsed = SearchQuery::parse($text, $min);

        if ($parsed->boolean !== null) {
            $ft = (clone $query)->join('article_search_index as si', 'si.article_id', '=', 'articles.id')
                ->whereRaw('MATCH(si.title, si.excerpt, si.body, si.taxonomy) AGAINST (? IN BOOLEAN MODE)', [$parsed->boolean]);
            if ((clone $ft)->toBase()->exists()) {
                $query->join('article_search_index as si', 'si.article_id', '=', 'articles.id')
                    ->whereRaw('MATCH(si.title, si.excerpt, si.body, si.taxonomy) AGAINST (? IN BOOLEAN MODE)', [$parsed->boolean])
                    ->select('articles.*')
                    ->orderByRaw(
                        '(MATCH(si.title, si.excerpt, si.body, si.taxonomy) AGAINST (? IN BOOLEAN MODE)'
                        .' + 4 * MATCH(si.title) AGAINST (? IN BOOLEAN MODE)'
                        .' + 2 * MATCH(si.excerpt) AGAINST (? IN BOOLEAN MODE)'
                        .' + 0.5 * MATCH(si.taxonomy) AGAINST (? IN BOOLEAN MODE)) DESC',
                        [$parsed->boolean, $parsed->optional, $parsed->optional, $parsed->optional]
                    )
                    ->tap(fn ($q) => DrfQuery::orderPg($q, 'articles.published_at', 'desc'))
                    ->orderByDesc('articles.id');

                return;
            }
        }

        $words = $parsed->likeTerms() ?: SearchQuery::fallbackWords($text);
        if ($words === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->join('article_search_index as si', 'si.article_id', '=', 'articles.id')->select('articles.*');
        foreach (array_slice($words, 0, 8) as $w) {
            $like = '%'.addcslashes($w, '\\%_').'%';
            $query->where(fn ($q) => $q->where('si.title', 'like', $like)->orWhere('si.excerpt', 'like', $like)
                ->orWhere('si.body', 'like', $like)->orWhere('si.taxonomy', 'like', $like));
        }
        foreach ($parsed->excluded as $w) {
            $like = '%'.addcslashes($w, '\\%_').'%';
            $query->where('si.title', 'not like', $like)->where('si.excerpt', 'not like', $like)->where('si.body', 'not like', $like);
        }
        $first = '%'.addcslashes($words[0], '\\%_').'%';
        $query->orderByRaw('(CASE WHEN si.title LIKE ? THEN 2 ELSE 0 END + CASE WHEN si.excerpt LIKE ? THEN 1 ELSE 0 END) DESC', [$first, $first])
            ->tap(fn ($q) => DrfQuery::orderPg($q, 'articles.published_at', 'desc'))->orderByDesc('articles.id');
    }

    private function pageSize(Request $request): int
    {
        $max = max(1, (int) config('portal.search_page_size_max', 50));
        $default = (int) config('portal.page_size', 20);
        $raw = $request->query('page_size');
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return min((int) $raw, $max);
        }

        return min($default, $max);
    }
}
