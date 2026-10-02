<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $guarded = [];

    /** TEXT/JSON columns cannot carry a DB default on MySQL, so the defaults live here. */
    protected $attributes = [
        'description' => '',
    ];

    protected function casts(): array
    {
        return ['price_amount' => 'decimal:2', 'duration_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
