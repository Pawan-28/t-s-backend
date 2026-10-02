<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingSchedule extends Model
{
    public const PENDING = 'PENDING';

    public const EXECUTED = 'EXECUTED';

    public const CANCELLED = 'CANCELLED';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'executed_at' => 'datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_id');
    }
}
