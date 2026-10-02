<?php

namespace App\Providers;

use App\Models\Article;
use App\Services\Search\ArticleSearchIndexer;
use Illuminate\Support\ServiceProvider;

/**
 * Articles / taxonomy / search wiring: keeps the article_search_index table current.
 * - Article `saved`: rebuild when a searchable attribute changed (or on create).
 * - Tag / taxonomy changes: ArticleService (tag sync) and the taxonomy
 *   controllers call the indexer explicitly (Eloquent has no pivot-sync events).
 */
class ArticleServiceProvider extends ServiceProvider
{
    public const SEARCHABLE = ['title', 'excerpt', 'content', 'location_name', 'subcategory_id', 'category_id', 'access_level'];

    public function register(): void
    {
        $this->app->singleton(ArticleSearchIndexer::class);
    }

    public function boot(): void
    {
        Article::saved(function (Article $article) {
            if ($article->wasRecentlyCreated || $article->wasChanged(self::SEARCHABLE)) {
                app(ArticleSearchIndexer::class)->refresh($article);
            }
        });
    }
}
