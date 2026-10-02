<?php

namespace App\Providers;

use App\Console\Commands\ExpirePendingPlagiarismChecks;
use App\Events\ArticleViewed;
use App\Jobs\FlushArticleViews;
use App\Services\Analytics\ViewRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Agent5: AI check + Copyleaks + analytics wiring (listener, limiters, command, scheduler). */
class AnalyticsAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ViewRecorder::class);
    }

    public function boot(): void
    {
        // The ONLY entry point for view counting.
        Event::listen(ArticleViewed::class, [ViewRecorder::class, 'handle']);

        $by = fn (Request $r) => 'u:'.($r->user()?->getAuthIdentifier() ?? $r->ip());
        RateLimiter::for('ai-check', fn (Request $r) => $r->isMethod('post')
            ? Limit::perMinute((int) config('portal.ai_checks.ai_per_minute', 6))->by($by($r))
            : Limit::none());
        RateLimiter::for('plagiarism-check', fn (Request $r) => $r->isMethod('post')
            ? Limit::perMinute((int) config('portal.ai_checks.plagiarism_per_minute', 6))->by($by($r))
            : Limit::none());

        $this->commands([ExpirePendingPlagiarismChecks::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Redis driver only: with ANALYTICS_DRIVER=database views are written straight to article_daily_views.
            $schedule->job(new FlushArticleViews)->everyMinute()->name('analytics-flush-views')
                ->when(fn () => config('portal.analytics.driver') === 'redis');
            $schedule->command('plagiarism:expire-pending')->everyFifteenMinutes()->name('plagiarism-expire-pending');
        });
    }
}
