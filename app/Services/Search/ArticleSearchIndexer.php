<?php

namespace App\Services\Search;

use App\Enums\AccessLevel;
use App\Models\Article;
use App\Support\PlainText;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the `article_search_index` table (MySQL/MariaDB InnoDB FULLTEXT). One row per article:
 * title (weight A), excerpt + location (B), plain-text body (C, PUBLIC articles only) and
 * subcategory / effective category / effective industry / tag names (D) so searching a taxonomy or
 * tag name finds articles that never use the word. The weights are applied at query time (SearchController).
 * Written with a plain upsert (no model events); all text is bound, never interpolated.
 */
class ArticleSearchIndexer
{
    /** Keep the indexed body bounded (LONGTEXT could hold more, but relevance beyond this is noise). */
    private const MAX_BODY_CHARS = 400000;

    public function refresh(Article|int $article): void
    {
        $id = $article instanceof Article ? $article->getKey() : $article;
        $model = Article::query()
            ->with(['subcategory.category.industry', 'legacyCategory.industry', 'tags'])
            ->find($id);
        if (! $model) {
            return;
        }

        $names = [];
        if ($model->subcategory) {
            $names[] = $model->subcategory->name;
        }
        $category = $model->effective_category;
        if ($category) {
            $names[] = $category->name;
            if ($category->industry) {
                $names[] = $category->industry->name;
            }
        }
        foreach ($model->tags as $tag) {
            $names[] = $tag->name;
        }

        // Restricted (subscriber-only) bodies are NOT indexed: public search would otherwise reveal which
        // words a paywalled article contains (match/rank oracle). Their title, excerpt, location, tags and
        // taxonomy names stay searchable, exactly what an anonymous reader may see anyway.
        $body = $model->access_level === AccessLevel::PUBLIC
            ? mb_substr(PlainText::fromHtml($model->content), 0, self::MAX_BODY_CHARS)
            : '';

        DB::table('article_search_index')->upsert([[
            'article_id' => $model->id,
            'title' => mb_substr((string) $model->title, 0, 255),
            'excerpt' => trim($model->excerpt.' '.$model->location_name),
            'body' => $body,
            'taxonomy' => implode(' ', $names),
        ]], ['article_id'], ['title', 'excerpt', 'body', 'taxonomy']);
    }

    /** @param iterable<int> $ids */
    public function refreshMany(iterable $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $this->refresh((int) $id);
            $n++;
        }

        return $n;
    }

    /** Rebuild every article (backfill / import). Returns the number processed. */
    public function reindexAll(int $chunk = 200, ?callable $tick = null): int
    {
        $n = 0;
        Article::query()->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$n, $tick) {
            foreach ($rows as $row) {
                $this->refresh($row->id);
                $n++;
            }
            if ($tick) {
                $tick($n);
            }
        });

        return $n;
    }
}
