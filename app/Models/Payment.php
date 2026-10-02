<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const CREATED = 'CREATED';

    public const PAID = 'PAID';

    public const FAILED = 'FAILED';

    protected $guarded = [];

    protected $hidden = ['razorpay_signature'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
