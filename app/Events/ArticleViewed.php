<?php

namespace App\Events;

use App\Models\Article;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the public article-detail endpoint for a PUBLISHED article that
 * the caller could read. Analytics listens (Redis counter); nothing else may
 * depend on it. `viewerKey` is a stable non-PII hash (user id or ip+ua hash).
 */
class ArticleViewed
{
    use Dispatchable;

    public function __construct(public Article $article, public string $viewerKey) {}
}
