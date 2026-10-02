<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Drains the Redis view counters into article_daily_views (upsert-increment). Redis driver only.
 *
 * Exactly-once accounting: every counter is drained with an atomic Lua
 * GETDEL+SREM (a view arriving afterwards simply re-creates the counter for the
 * next run), then added to MySQL with `views = views + n`. If the DB write
 * fails the drained amount is put back into Redis, so nothing is lost or
 * counted twice. A Redis lock (flush_lock_seconds) keeps runs from overlapping.
 */
class ViewFlusher
{
    public const LOCK_KEY = 'analytics:flush:lock';

    private const DRAIN_LUA = <<<'LUA'
local v = redis.call('GETDEL', KEYS[1])
redis.call('SREM', KEYS[2], ARGV[1])
if v then return tonumber(v) end
return 0
LUA;

    private const RESTORE_LUA = <<<'LUA'
redis.call('INCRBY', KEYS[1], tonumber(ARGV[1]))
redis.call('SADD', KEYS[2], ARGV[2])
return 1
LUA;

    private const UNLOCK_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end
return 0
LUA;

    /**
     * Scheduled entry point. With ANALYTICS_DRIVER=database views are already durable in article_daily_views
     * (see ViewRecorder), so this is a deliberate no-op; with `redis` it drains the counters.
     *
     * @return array{skipped:bool, flushed_articles:int, failed_articles:int, total_views_persisted:int}
     */
    public function flush(): array
    {
        if (ViewRecorder::driver() !== 'redis') {
            return ['skipped' => false, 'flushed_articles' => 0, 'failed_articles' => 0, 'total_views_persisted' => 0];
        }

        return $this->flushRedis();
    }

    /**
     * Drain the Redis counters regardless of the selected driver (`php artisan analytics:flush --redis`),
     * e.g. once when switching a deployment from `redis` to `database` so no pending counts are stranded.
     *
     * @return array{skipped:bool, flushed_articles:int, failed_articles:int, total_views_persisted:int}
     */
    public function flushRedis(): array
    {
        $redis = Redis::connection('analytics');
        $token = Str::random(32);
        $ttl = max(10, (int) config('portal.analytics.flush_lock_seconds', 300));

        if (! $redis->set(self::LOCK_KEY, $token, 'EX', $ttl, 'NX')) {
            return ['skipped' => true, 'flushed_articles' => 0, 'failed_articles' => 0, 'total_views_persisted' => 0];
        }

        $flushed = $failed = $total = 0;
        try {
            $members = $redis->smembers(ViewRecorder::PENDING_KEY) ?: [];
            $today = now()->toDateString();

            foreach ($members as $member) {
                [$articleId, $date, $key] = $this->parse((string) $member, $today);
                if ($articleId === null) {
                    $redis->srem(ViewRecorder::PENDING_KEY, $member);

                    continue;
                }

                $count = (int) $redis->eval(self::DRAIN_LUA, 2, $key, ViewRecorder::PENDING_KEY, $member);
                if ($count <= 0) {
                    continue;
                }

                try {
                    if ($this->persist($articleId, $date, $count)) {
                        $flushed++;
                        $total += $count;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error("Analytics flush: could not persist {$count} view(s) for article #{$articleId}; restored to Redis: ".$e->getMessage());
                    $redis->eval(self::RESTORE_LUA, 2, $key, ViewRecorder::PENDING_KEY, $count, $member);
                }
            }
        } finally {
            try {
                $redis->eval(self::UNLOCK_LUA, 1, self::LOCK_KEY, $token);
            } catch (\Throwable) {
                // the lock's TTL clears it anyway
            }
        }

        return ['skipped' => false, 'flushed_articles' => $flushed, 'failed_articles' => $failed, 'total_views_persisted' => $total];
    }

    /**
     * "<id>:<date>" (current format) or a bare "<id>" (legacy Django counter
     * analytics:article_views:<id>, attributed to today).
     *
     * @return array{0:?int,1:string,2:string}
     */
    private function parse(string $member, string $today): array
    {
        if (preg_match('/^(\d+):(\d{4}-\d{2}-\d{2})$/', $member, $m)) {
            return [(int) $m[1], $m[2], ViewRecorder::counterKey((int) $m[1], $m[2])];
        }
        if (ctype_digit($member)) {
            return [(int) $member, $today, "analytics:article_views:{$member}"];
        }

        return [null, $today, ''];
    }

    private function persist(int $articleId, string $date, int $count): bool
    {
        $exists = DB::table('articles')->where('id', $articleId)->exists();
        if (! $exists) {
            return false; // article deleted meanwhile: nothing to attribute the views to
        }

        // Own transaction/savepoint: a failed write must never poison an outer transaction.
        DB::transaction(fn () => DB::statement(
            'INSERT INTO article_daily_views (article_id, `date`, views, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE views = views + VALUES(views), updated_at = VALUES(updated_at)',
            [$articleId, $date, $count, now(), now()],
        ));

        return true;
    }
}
