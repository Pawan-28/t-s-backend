<?php

namespace App\Models;

use App\Enums\AdPlacement;
use Illuminate\Database\Eloquent\Model;

class Advertisement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'placement' => AdPlacement::class,
            'is_active' => 'boolean',
            'priority' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active && $this->start_at <= now() && now() <= $this->end_at;
    }
}
