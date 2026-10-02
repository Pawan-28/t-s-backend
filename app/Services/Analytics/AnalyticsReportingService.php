<?php

namespace App\Services\Analytics;

use App\Models\Article;
use App\Support\DrfQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates over article_daily_views + articles for the admin
 * analytics APIs. Popular/performance reports only ever count PUBLISHED
 * articles. Empty data yields zeros/empty arrays (nothing is fabricated).
 */
class AnalyticsReportingService
{
    public static function totalViews(): int
    {
        return (int) DB::table('article_daily_views as v')
            ->join('articles as a', 'a.id', '=', 'v.article_id')
            ->where('a.status', 'PUBLISHED')
            ->sum('v.views');
    }

    /** @return Collection<int, Article> annotated with total_views */
    public static function popularArticles(int $limit)
    {
        $limit = max(1, min($limit, 50));

        return Article::query()
            ->where('status', 'PUBLISHED')
            ->with(['subcategory.category.industry', 'legacyCategory.industry'])
            ->withSum('dailyViews as total_views_sum', 'views')
            ->orderByRaw('COALESCE((SELECT SUM(views) FROM article_daily_views WHERE article_id = articles.id), 0) DESC')
            ->tap(fn ($q) => DrfQuery::orderPg($q, 'published_at', 'desc'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Per-published-article view totals, shared by the by-taxonomy reports. */
    private static function articleViewTotals()
    {
        return DB::table('article_daily_views')->selectRaw('article_id, SUM(views) as total')->groupBy('article_id');
    }

    /** Effective category id: the subcategory's category, else the legacy category. */
    public static function byIndustry(): array
    {
        return DB::table('articles as a')
            ->leftJoin('subcategories as s', 's.id', '=', 'a.subcategory_id')
            ->join('categories as c', 'c.id', '=', DB::raw('COALESCE(s.category_id, a.category_id)'))
            ->join('industries as i', 'i.id', '=', 'c.industry_id')
            ->leftJoinSub(self::articleViewTotals(), 'v', 'v.article_id', '=', 'a.id')
            ->where('a.status', 'PUBLISHED')
            ->groupBy('i.id', 'i.name', 'i.slug')
            ->selectRaw('i.id as industry_id, i.name, i.slug, COALESCE(SUM(v.total), 0) as total_views')
            ->orderByRaw('COALESCE(SUM(v.total), 0) DESC')
            ->orderBy('i.id')
            ->get()
            ->map(fn ($r) => [
                'industry_id' => (int) $r->industry_id,
                'name' => $r->name,
                'slug' => $r->slug,
                'total_views' => (int) $r->total_views,
            ])->all();
    }

    public static function byCategory(): array
    {
        return DB::table('articles as a')
            ->leftJoin('subcategories as s', 's.id', '=', 'a.subcategory_id')
            ->join('categories as c', 'c.id', '=', DB::raw('COALESCE(s.category_id, a.category_id)'))
            ->leftJoin('industries as i', 'i.id', '=', 'c.industry_id')
            ->leftJoinSub(self::articleViewTotals(), 'v', 'v.article_id', '=', 'a.id')
            ->where('a.status', 'PUBLISHED')
            ->groupBy('c.id', 'c.name', 'c.slug', 'i.name')
            ->selectRaw('c.id as category_id, c.name, c.slug, i.name as industry, COALESCE(SUM(v.total), 0) as total_views')
            ->orderByRaw('COALESCE(SUM(v.total), 0) DESC')
            ->orderBy('c.id')
            ->get()
            ->map(fn ($r) => [
                'category_id' => (int) $r->category_id,
                'name' => $r->name,
                'slug' => $r->slug,
                'industry' => $r->industry,
                'total_views' => (int) $r->total_views,
            ])->all();
    }

    public static function bySubcategory(): array
    {
        return DB::table('articles as a')
            ->join('subcategories as s', 's.id', '=', 'a.subcategory_id')
            ->join('categories as c', 'c.id', '=', 's.category_id')
            ->leftJoinSub(self::articleViewTotals(), 'v', 'v.article_id', '=', 'a.id')
            ->where('a.status', 'PUBLISHED')
            ->groupBy('s.id', 's.name', 's.slug', 'c.name')
            ->selectRaw('s.id as subcategory_id, s.name, s.slug, c.name as category, COALESCE(SUM(v.total), 0) as total_views')
            ->orderByRaw('COALESCE(SUM(v.total), 0) DESC')
            ->orderBy('s.id')
            ->get()
            ->map(fn ($r) => [
                'subcategory_id' => (int) $r->subcategory_id,
                'name' => $r->name,
                'slug' => $r->slug,
                'category' => $r->category,
                'total_views' => (int) $r->total_views,
            ])->all();
    }

    public static function reporterSubmissions(): array
    {
        return DB::table('articles as a')
            ->join('users as u', 'u.id', '=', 'a.author_id')
            ->groupBy('a.author_id', 'u.first_name', 'u.last_name', 'u.email')
            ->selectRaw("a.author_id, u.first_name, u.last_name, u.email,
                SUM(CASE WHEN a.status <> 'DRAFT' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN a.status = 'PUBLISHED' THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN a.status IN ('SUBMITTED','UNDER_REVIEW') THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN a.status = 'REJECTED' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN a.status = 'CHANGES_REQUESTED' THEN 1 ELSE 0 END) as changes_requested,
                COUNT(*) as total")
            ->orderByDesc('total')
            ->orderBy('a.author_id')
            ->get()
            ->map(fn ($r) => [
                'reporter_id' => (int) $r->author_id,
                'name' => trim($r->first_name.' '.$r->last_name) ?: $r->email,
                'total_articles' => (int) $r->total,
                'submitted' => (int) $r->submitted,
                'published' => (int) $r->published,
                'pending_review' => (int) $r->pending_review,
                'rejected' => (int) $r->rejected,
                'changes_requested' => (int) $r->changes_requested,
            ])->all();
    }

    public static function viewsOverTime(int $days): array
    {
        $days = max(1, min($days, 90));
        $since = now()->subDays($days - 1)->toDateString();

        return DB::table('article_daily_views as v')
            ->join('articles as a', 'a.id', '=', 'v.article_id')
            ->where('a.status', 'PUBLISHED')
            ->where('v.date', '>=', $since)
            ->groupBy('v.date')
            ->orderBy('v.date')
            ->selectRaw('v.date as day, SUM(v.views) as total')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->day, 'views' => (int) $r->total])
            ->all();
    }

    public static function publishingActivity(int $days): array
    {
        $days = max(1, min($days, 90));
        $since = now()->subDays($days);

        $rows = DB::table('articles')
            ->where('status', 'PUBLISHED')
            ->where('published_at', '>=', $since)
            ->selectRaw('DATE(published_at) as day, COUNT(*) as c')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return [
            'published_count' => Article::where('status', 'PUBLISHED')->count(),
            'scheduled_count' => Article::where('status', 'SCHEDULED')->count(),
            'published_last_n_days' => $rows->map(fn ($r) => ['date' => (string) $r->day, 'count' => (int) $r->c])->all(),
        ];
    }
}
