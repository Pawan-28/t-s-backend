<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\Search\ArticleSearchIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds the search vector of every article affected by a taxonomy/tag rename
 * (their names live in the weight-D text). $scope is industry|category|subcategory
 * (by id) or an explicit list of article ids.
 */
class ReindexArticleSearch implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @param list<int> $articleIds */
    public function __construct(public string $scope, public int $id = 0, public array $articleIds = []) {}

    public function handle(ArticleSearchIndexer $indexer): void
    {
        $query = Article::query()->select('articles.id');
        match ($this->scope) {
            'subcategory' => $query->where('subcategory_id', $this->id),
            'category' => $query->where(fn ($q) => $q->where('category_id', $this->id)
                ->orWhereHas('subcategory', fn ($s) => $s->where('category_id', $this->id))),
            'industry' => $query->where(fn ($q) => $q
                ->whereHas('subcategory.category', fn ($c) => $c->where('industry_id', $this->id))
                ->orWhereHas('legacyCategory', fn ($c) => $c->where('industry_id', $this->id))),
            'tag' => $query->whereIn('id', $this->articleIds),
            default => $query->whereIn('id', $this->articleIds),
        };
        $query->orderBy('articles.id')->chunkById(200, function ($rows) use ($indexer) {
            foreach ($rows as $row) {
                $indexer->refresh($row->id);
            }
        }, 'articles.id', 'id');
    }
}
