<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class NotifySubscribersOfPublishedArticles extends Command
{
    protected $signature = 'subscriptions:notify-published';

    protected $description = 'WhatsApp/e-mail active subscribers about newly published non-public articles (once per article)';

    public function handle(SubscriptionService $service): int
    {
        $this->info('Processed '.$service->notifySubscribersOfPublishedArticles().' article(s).');

        return self::SUCCESS;
    }
}
