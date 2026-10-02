<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    public const PENDING = 'PENDING';

    public const ACTIVE = 'ACTIVE';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiring_notified_at' => 'datetime',
            'expired_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getIsActiveNowAttribute(): bool
    {
        return $this->status === self::ACTIVE && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** Email used for notices: contact snapshot, else account email. */
    public function getNotificationEmailAttribute(): string
    {
        return $this->contact_email ?: (string) $this->user?->email;
    }
}
