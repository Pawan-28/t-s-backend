<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

class PlagiarismCheckImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'requested_by_id', 'provider', 'scan_id', 'status', 'error_message', 'similarity_score', 'matches', 'created_at', 'completed_at'];

    protected const EXPRESSIONS = ['matches' => 'matches::text'];

    public function name(): string
    {
        return 'plagiarism_checks';
    }

    public function sourceTable(): string
    {
        return 'ai_plagiarismcheckresult';
    }

    public function optional(): bool
    {
        return true;
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('articles', $row['article_id'])) {
            $ctx->skip('plagiarism_checks', $id, 'PLG-ARTICLE-ORPHAN', 'article '.$row['article_id'].' does not exist or was not imported');

            return null;
        }

        return [
            'id' => $id,
            'article_id' => (int) $row['article_id'],
            'requested_by_id' => $row['requested_by_id'] !== null && $ctx->has('users', $row['requested_by_id']) ? (int) $row['requested_by_id'] : null,
            'provider' => $row['provider'],
            'scan_id' => $ctx->plan->value('plagiarism_checks', 'scan_id', $id, $row['scan_id']),
            'status' => in_array($row['status'], Rules::CHECK_STATUSES, true) ? $row['status'] : Rules::DEFAULT_CHECK_STATUS,
            'error_message' => self::str($row['error_message']),
            'similarity_score' => $row['similarity_score'],
            'matches' => self::json($row['matches']),
            'created_at' => $row['created_at'],
            'completed_at' => $row['completed_at'],
        ];
    }
}
