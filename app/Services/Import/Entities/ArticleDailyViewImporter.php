<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class ArticleDailyViewImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'date', 'views', 'created_at', 'updated_at'];

    protected const EXPRESSIONS = ['date' => 'date::text'];

    /** @var array<string, true> */
    private array $seen = [];

    public function name(): string
    {
        return 'article_daily_views';
    }

    public function sourceTable(): string
    {
        return 'analytics_articledailyview';
    }

    public function optional(): bool
    {
        return true;
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('articles', $row['article_id'])) {
            $ctx->skip('article_daily_views', $id, 'VIEW-ARTICLE-ORPHAN', 'article '.$row['article_id'].' does not exist or was not imported');

            return null;
        }
        $key = $row['article_id'].':'.$row['date'];
        if (isset($this->seen[$key])) {
            $ctx->skip('article_daily_views', $id, 'VIEW-DUP', 'duplicate (article, date)');

            return null;
        }
        $this->seen[$key] = true;

        return ['id' => $id, 'article_id' => (int) $row['article_id'], 'date' => $row['date'], 'views' => (int) $row['views'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }
}
