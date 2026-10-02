<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlagiarismCheckResult extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** TEXT/JSON columns cannot carry a DB default on MySQL, so the defaults live here. */
    protected $attributes = [
        'error_message' => '',
        'matches' => '[]',
    ];

    protected function casts(): array
    {
        return ['matches' => 'array', 'similarity_score' => 'float', 'completed_at' => 'datetime'];
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
