<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

/** raw_response (full provider payload, may embed article text) is intentionally not imported. */
class AiAnalysisImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'article_id', 'requested_by_id', 'provider', 'model_name', 'status', 'error_message', 'readability_score', 'grammar_issues', 'seo_suggestions', 'ai_content_likelihood', 'ai_content_rationale', 'created_at'];

    protected const EXPRESSIONS = ['grammar_issues' => 'grammar_issues::text', 'seo_suggestions' => 'seo_suggestions::text'];

    public function name(): string
    {
        return 'ai_analyses';
    }

    public function sourceTable(): string
    {
        return 'ai_aianalysisresult';
    }

    public function optional(): bool
    {
        return true;
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        if (! $ctx->has('articles', $row['article_id'])) {
            $ctx->skip('ai_analyses', $id, 'AI-ARTICLE-ORPHAN', 'article '.$row['article_id'].' does not exist or was not imported');

            return null;
        }

        return [
            'id' => $id,
            'article_id' => (int) $row['article_id'],
            'requested_by_id' => $row['requested_by_id'] !== null && $ctx->has('users', $row['requested_by_id']) ? (int) $row['requested_by_id'] : null,
            'provider' => $row['provider'],
            'model_name' => self::str($row['model_name']),
            'status' => in_array($row['status'], Rules::CHECK_STATUSES, true) ? $row['status'] : Rules::DEFAULT_CHECK_STATUS,
            'error_message' => self::str($row['error_message']),
            'readability_score' => $row['readability_score'],
            'grammar_issues' => self::json($row['grammar_issues']),
            'seo_suggestions' => self::json($row['seo_suggestions']),
            'ai_content_likelihood' => $row['ai_content_likelihood'],
            'ai_content_rationale' => self::str($row['ai_content_rationale']),
            'created_at' => $row['created_at'],
        ];
    }
}
