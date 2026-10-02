<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark ACTIVE subscriptions past expires_at as EXPIRED and notify once';

    public function handle(SubscriptionService $service): int
    {
        $this->info('Expired '.$service->expireOverdue().' subscription(s).');

        return self::SUCCESS;
    }
}
