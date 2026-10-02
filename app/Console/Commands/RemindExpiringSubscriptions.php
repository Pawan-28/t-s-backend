<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:remind-expiring';

    protected $description = 'Send one "expiring soon" reminder for ACTIVE subscriptions expiring within portal.subscriptions.expiry_reminder_days';

    public function handle(SubscriptionService $service): int
    {
        $this->info('Reminded '.$service->remindExpiring().' subscription(s).');

        return self::SUCCESS;
    }
}
