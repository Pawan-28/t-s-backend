<?php

namespace App\Http\Resources;

use App\Models\AiAnalysisResult;
use App\Models\PlagiarismCheckResult;

/** Array serializers for AI / plagiarism results (Django AIAnalysisResultSerializer et al.). */
class AiPlagiarismResources
{
    public static function analysis(AiAnalysisResult $r): array
    {
        return [
            'id' => $r->id,
            'provider' => $r->provider,
            'model_name' => $r->model_name,
            'status' => $r->status,
            'error_message' => $r->error_message ?? '',
            'readability_score' => $r->readability_score,
            'grammar_issues' => $r->grammar_issues ?? [],
            'seo_suggestions' => $r->seo_suggestions ?? [],
            'ai_content_likelihood' => $r->ai_content_likelihood,
            'ai_content_rationale' => $r->ai_content_rationale ?? '',
            'requested_by_email' => $r->requestedBy?->email,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    public static function plagiarism(PlagiarismCheckResult $r): array
    {
        return [
            'id' => $r->id,
            'provider' => $r->provider,
            'scan_id' => $r->scan_id,
            'status' => $r->status,
            'error_message' => $r->error_message ?? '',
            'similarity_score' => $r->similarity_score,
            'matches' => $r->matches ?? [],
            'requested_by_email' => $r->requestedBy?->email,
            'created_at' => $r->created_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
        ];
    }

    /** Extra article context on the site-wide admin lists (relations must be eager-loaded). */
    public static function articleRef(AiAnalysisResult|PlagiarismCheckResult $r): array
    {
        $a = $r->article;

        return [
            'article_id' => $r->article_id,
            'article_title' => $a->title,
            'article_slug' => $a->slug,
            'article_status' => $a->status->value,
            'article_author_email' => $a->author?->email,
            'article_assigned_reporter_email' => $a->assignedReporter?->email,
        ];
    }

    public static function adminAnalysis(AiAnalysisResult $r): array
    {
        return self::analysis($r) + self::articleRef($r);
    }

    public static function adminPlagiarism(PlagiarismCheckResult $r): array
    {
        return self::plagiarism($r) + self::articleRef($r);
    }
}
