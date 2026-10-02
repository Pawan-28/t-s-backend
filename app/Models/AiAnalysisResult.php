<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnalysisResult extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** TEXT/JSON columns cannot carry a DB default on MySQL, so the defaults live here. */
    protected $attributes = [
        'error_message' => '',
        'grammar_issues' => '[]',
        'seo_suggestions' => '[]',
        'ai_content_rationale' => '',
    ];

    protected function casts(): array
    {
        return [
            'grammar_issues' => 'array',
            'seo_suggestions' => 'array',
            'readability_score' => 'float',
            'ai_content_likelihood' => 'float',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
