<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;

class ArticleTagImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'tag_id'];

    /** @var array<string, true> */
    private array $seen = [];

    public function name(): string
    {
        return 'article_tags';
    }

    public function sourceTable(): string
    {
        return 'articles_article_tags';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('articles', $row['article_id'])) {
            $ctx->skip('article_tags', $id, 'ART-TAG-ORPHAN', 'article '.$row['article_id'].' does not exist or was not imported');

            return null;
        }
        if (! $ctx->has('tags', $row['tag_id'])) {
            $ctx->skip('article_tags', $id, 'ART-TAG-ORPHAN', 'tag '.$row['tag_id'].' does not exist or was not imported');

            return null;
        }
        $key = $row['article_id'].':'.$row['tag_id'];
        if (isset($this->seen[$key])) {
            $ctx->skip('article_tags', $id, 'ART-TAG-DUP', 'duplicate (article, tag) pair');

            return null;
        }
        $this->seen[$key] = true;

        return ['id' => $id, 'article_id' => (int) $row['article_id'], 'tag_id' => (int) $row['tag_id']];
    }
}
