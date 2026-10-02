<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

class ArticleReviewImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'reviewer_id', 'action', 'from_status', 'to_status', 'reason', 'created_at'];

    public function name(): string
    {
        return 'article_reviews';
    }

    public function sourceTable(): string
    {
        return 'reporters_articlereview';
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('articles', $row['article_id'])) {
            $ctx->skip('article_reviews', $id, 'REV-ARTICLE-ORPHAN', 'article '.$row['article_id'].' does not exist or was not imported');

            return null;
        }
        if (! in_array($row['action'], Rules::reviewActions(), true) || ! in_array($row['from_status'], Rules::articleStatuses(), true) || ! in_array($row['to_status'], Rules::articleStatuses(), true)) {
            $ctx->skip('article_reviews', $id, 'REV-VALUE-INVALID', 'action/from_status/to_status is not a known value');

            return null;
        }

        return [
            'id' => $id,
            'article_id' => (int) $row['article_id'],
            'reviewer_id' => $row['reviewer_id'] !== null && $ctx->has('users', $row['reviewer_id']) ? (int) $row['reviewer_id'] : null,
            'action' => $row['action'],
            'from_status' => $row['from_status'],
            'to_status' => $row['to_status'],
            'reason' => self::str($row['reason']),
            'created_at' => $row['created_at'],
        ];
    }
}
