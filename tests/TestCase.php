<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Authenticate as $user with a real-shaped ACCESS token (ability "access"). */
    protected function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user, ['access']);

        return $this;
    }

    /**
     * Run what a cron-driven worker would (`queue:work --stop-when-empty`) when the default queue is not `sync`,
     * so tests asserting queued side effects (WATI calls, search reindex...) hold for QUEUE_CONNECTION=database too.
     * A no-op with sync (jobs already ran inline).
     */
    protected function drainQueue(): void
    {
        $connection = (string) config('queue.default');
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            return;
        }
        Artisan::call('queue:work', ['connection' => $connection, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3, '--max-time' => 30]);
    }

    /** Only touches Redis when a Redis driver is actually selected (never connects in the database profile). */
    protected function flushRedis(): void
    {
        $selected = config('cache.stores.'.config('cache.default').'.driver') === 'redis'
            || config('queue.connections.'.config('queue.default').'.driver') === 'redis'
            || config('portal.analytics.driver') === 'redis';
        if (! $selected) {
            return;
        }
        try {
            Redis::connection('default')->flushdb();
            Redis::connection('analytics')->flushdb();
        } catch (\Throwable) {
        }
    }
}
