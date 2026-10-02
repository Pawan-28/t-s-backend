<?php

namespace App\Providers;

use App\Services\Workflow\ArticleWorkflowService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArticleWorkflowService::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Django beat: publish due schedules every 60 s. (The subscriber fan-out catch-up scan
            // `subscriptions:notify-published` is owned and scheduled by the subscriptions area.)
            $schedule->command('articles:publish-due')
                ->everyMinute()->withoutOverlapping(10)->onOneServer();
        });
    }
}
