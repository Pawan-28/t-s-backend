<?php

namespace App\Http\Controllers\Api;

use App\Events\ArticleViewed;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleDailyView;
use App\Services\Analytics\AnalyticsReportingService as Reports;
use App\Services\Analytics\ViewRecorder;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnalyticsController extends Controller
{
    /**
     * POST /api/analytics/articles/{slug}/view/ (public, fire-and-forget from the
     * frontend). PUBLISHED only (else 404). The body is ignored. Counting goes
     * through ArticleViewed -> ViewRecorder, so it is deduped and fail-open.
     */
    public function view(Request $request, string $slug, ViewRecorder $recorder): JsonResponse
    {
        $article = Article::where('slug', $slug)->where('status', 'PUBLISHED')->firstOrFail();

        $recorder->resetLast();
        $key = ViewRecorder::viewerKey($request);
        $recorder->viaTracker(fn () => ArticleViewed::dispatch($article, $key));

        return response()->json(['recorded' => $recorder->lastRecorded() === true], 202);
    }

    public function overview(): JsonResponse
    {
        return response()->json(['total_views' => Reports::totalViews()]);
    }

    public function popular(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $limit = is_numeric($limit) ? (int) $limit : 10;

        $rows = Reports::popularArticles($limit)->map(function (Article $a) {
            $category = $a->effective_category;
            $industry = $category?->industry;

            return [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'total_views' => (int) ($a->total_views_sum ?? 0),
                'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
                'industry' => $industry ? ['id' => $industry->id, 'name' => $industry->name, 'slug' => $industry->slug] : null,
                'published_at' => $a->published_at?->toIso8601String(),
            ];
        });

        return response()->json($rows->values()->all());
    }

    public function industries(): JsonResponse
    {
        return response()->json(Reports::byIndustry());
    }

    public function categories(): JsonResponse
    {
        return response()->json(Reports::byCategory());
    }

    public function subcategories(): JsonResponse
    {
        return response()->json(Reports::bySubcategory());
    }

    public function reporters(): JsonResponse
    {
        return response()->json(Reports::reporterSubmissions());
    }

    public function publishing(Request $request): JsonResponse
    {
        return response()->json(Reports::publishingActivity($this->days($request)));
    }

    public function viewsOverTime(Request $request): JsonResponse
    {
        return response()->json(Reports::viewsOverTime($this->days($request)));
    }

    private function days(Request $request): int
    {
        $d = $request->query('days', 30);

        return is_numeric($d) ? (int) $d : 30;
    }

    /**
     * GET /api/analytics/admin/article-daily-views/
     *   ?article=&date_after=&date_before=&search=&ordering=&page=
     */
    public function dailyViews(Request $request): JsonResponse
    {
        $q = ArticleDailyView::query()->with([
            'article.subcategory.category.industry',
            'article.legacyCategory.industry',
        ]);
        $errors = [];

        $article = $request->query('article');
        if ($article !== null && $article !== '') {
            if (ctype_digit((string) $article) && Article::whereKey((int) $article)->exists()) {
                $q->where('article_id', (int) $article);
            } else {
                $errors['article'] = ['Select a valid choice. That choice is not one of the available choices.'];
            }
        }
        foreach (['date_after' => '>=', 'date_before' => '<='] as $param => $op) {
            $v = $request->query($param);
            if ($v === null || $v === '') {
                continue;
            }
            $d = \DateTime::createFromFormat('!Y-m-d', (string) $v);
            if ($d && $d->format('Y-m-d') === $v) {
                $q->where('date', $op, $v);
            } else {
                $errors[$param] = ['Enter a valid date.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // DRF SearchFilter: every whitespace/comma separated term must match title OR slug.
        $terms = preg_split('/[\s,]+/', trim((string) $request->query('search', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $q->whereHas('article', fn ($a) => $a->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('slug', 'like', $like)));
        }

        $applied = false;
        foreach (array_filter(array_map('trim', explode(',', (string) $request->query('ordering', '')))) as $field) {
            $col = ltrim($field, '-');
            if (in_array($col, ['date', 'views'], true)) {
                $q->orderBy($col, str_starts_with($field, '-') ? 'desc' : 'asc');
                $applied = true;
            }
        }
        if (! $applied) {
            $q->orderByDesc('date');
        }
        $q->orderByDesc('id');

        return response()->json(Page::make($q, $request, function (ArticleDailyView $v) {
            $a = $v->article;
            $category = $a->effective_category;
            $industry = $category?->industry;

            return [
                'id' => $v->id,
                'date' => $v->date->format('Y-m-d'),
                'views' => (int) $v->views,
                'article_id' => $v->article_id,
                'article_title' => $a->title,
                'article_slug' => $a->slug,
                'article_status' => $a->status->value,
                'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
                'industry' => $industry ? ['id' => $industry->id, 'name' => $industry->name, 'slug' => $industry->slug] : null,
                'updated_at' => $v->updated_at?->toIso8601String(),
            ];
        }));
    }
}
