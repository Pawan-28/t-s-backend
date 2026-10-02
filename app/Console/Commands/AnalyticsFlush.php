<?php

namespace App\Console\Commands;

use App\Services\Analytics\ViewFlusher;
use App\Services\Analytics\ViewRecorder;
use Illuminate\Console\Command;

class AnalyticsFlush extends Command
{
    protected $signature = 'analytics:flush {--redis : Drain the Redis counters even if ANALYTICS_DRIVER=database (use once when switching drivers)}';

    protected $description = 'Move pending Redis view counters into article_daily_views (no-op for ANALYTICS_DRIVER=database)';

    public function handle(ViewFlusher $flusher): int
    {
        if ($this->option('redis')) {
            $result = $flusher->flushRedis();
        } elseif (ViewRecorder::driver() !== 'redis') {
            $this->info('ANALYTICS_DRIVER=database: views are written to article_daily_views immediately; nothing to flush.');

            return self::SUCCESS;
        } else {
            $result = $flusher->flush();
        }

        if ($result['skipped']) {
            $this->warn('Another flush holds the lock; skipped.');

            return self::SUCCESS;
        }
        $this->info("Flushed {$result['flushed_articles']} article/day counter(s), {$result['total_views_persisted']} view(s); {$result['failed_articles']} failed.");

        return $result['failed_articles'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
