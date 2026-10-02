<?php

namespace App\Console\Commands;

use App\Services\Workflow\ArticleWorkflowService;
use Illuminate\Console\Command;

class PublishDueArticles extends Command
{
    protected $signature = 'articles:publish-due';

    protected $description = 'Publish every scheduled article whose publish time has arrived';

    public function handle(ArticleWorkflowService $workflow): int
    {
        $n = $workflow->publishDueSchedules();
        $this->info("Published {$n} article(s).");

        return self::SUCCESS;
    }
}
