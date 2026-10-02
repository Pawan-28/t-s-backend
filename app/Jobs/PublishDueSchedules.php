<?php

namespace App\Jobs;

use App\Services\Workflow\ArticleWorkflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Publishes every PENDING PublishingSchedule whose time has come (Django: publish_due_scheduled_articles). */
class PublishDueSchedules implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(ArticleWorkflowService $workflow): void
    {
        $workflow->publishDueSchedules();
    }
}
