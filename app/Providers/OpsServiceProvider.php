<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Housekeeping schedule (token / failed-job pruning, cache pruning, scheduler heartbeat, optional
 * scheduler-driven queue worker). Domain jobs are scheduled by their own providers.
 */
class OpsServiceProvider extends ServiceProvider
{
    /** Cache key holding the ISO-8601 time of the last scheduler tick (read by `portal:doctor`). */
    public const HEARTBEAT_KEY = 'portal:scheduler:heartbeat';

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Proof that cron -> schedule:run really fires (portal:doctor reports its age). Fail-safe: a cache
            // problem must never break the rest of the scheduler run.
            $schedule->call(function () {
                try {
                    Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), 3600);
                } catch (\Throwable $e) {
                    report($e);
                }
            })->everyMinute()->name('portal-scheduler-heartbeat');

            // Expired access/refresh tokens are rejected on use anyway; this keeps the table small.
            $schedule->command('sanctum:prune-expired --hours=24')->dailyAt('03:10')->onOneServer()->name('sanctum-prune-expired');
            $schedule->command('queue:prune-failed --hours=168')->dailyAt('03:20')->onOneServer()->name('queue-prune-failed');
            $schedule->command('portal:prune-cache')->hourly()->withoutOverlapping(30)->name('portal-prune-cache');

            // Shared hosting (no daemon): the scheduler drains the queue itself, once a minute. `--max-time=50`
            // makes the worker exit before the next tick; withoutOverlapping(5) covers a job that overruns
            // (the mutex expires after 5 minutes if a run is killed). Off by default: a supervisor-managed
            // `queue:work` daemon (VPS) must not be duplicated. It is registered LAST on purpose: schedule:run executes
            // events in order and this one blocks for up to ~50 s, so it drains jobs that the earlier tasks of the same
            // tick just queued (publish-due -> subscriber notifications) and delays nothing that comes after it.
            if (config('portal.queue_via_scheduler')) {
                $connection = config('portal.queue_via_scheduler_connection') ?: config('queue.default');
                if (! in_array($connection, ['sync', 'null'], true)) {
                    $schedule->command("queue:work {$connection} --stop-when-empty --max-time=50 --tries=3")
                        ->everyMinute()->withoutOverlapping(5)->name('queue-work-via-scheduler');
                }
            }
        });
    }
}
