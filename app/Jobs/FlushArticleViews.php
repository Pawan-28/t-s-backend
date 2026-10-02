<?php

namespace App\Jobs;

use App\Services\Analytics\ViewFlusher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Scheduled every minute ONLY with ANALYTICS_DRIVER=redis (AnalyticsAiServiceProvider): Redis counters -> article_daily_views. */
class FlushArticleViews implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(ViewFlusher $flusher): void
    {
        try {
            $flusher->flush();
        } catch (\Throwable $e) {
            // Redis down etc.: nothing was drained, the next tick retries.
            Log::warning('Analytics flush skipped: '.$e->getMessage());
        }
    }
}
