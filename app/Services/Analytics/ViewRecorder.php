<?php

namespace App\Services\Analytics;

use App\Events\ArticleViewed;
use App\Support\ApiUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Hot path of the analytics pipeline (PDF s18). Two interchangeable drivers
 * (portal.analytics.driver, env ANALYTICS_DRIVER); the beacon and every admin
 * report behave identically with either:
 *
 * `database` (default, shared hosting, no Redis): dedupe with Cache::add on the configured cache
 *   store (`analytics:view_cooldown:<article>:<viewer-hash>`, TTL = cooldown, no PII), then ONE atomic
 *   `INSERT ... ON DUPLICATE KEY UPDATE views = views + 1` straight into article_daily_views. That single
 *   statement is the durable counter, so counting is exactly-once by construction and there is nothing
 *   to flush (ViewFlusher is a no-op). If the increment fails the dedupe marker is removed again so the
 *   viewer's next hit still counts (no silent loss beyond that one failed request).
 *
 * `redis`: ArticleViewed -> Redis counter -> (FlushArticleViews job) -> article_daily_views.
 *
 * Redis driver (connection `analytics`, empty prefix, keys exactly as Django's family):
 *   analytics:view_cooldown:<article_id>:<viewerKey>  SET NX EX cooldown (dedupe)
 *   analytics:article_views:<article_id>:<YYYY-MM-DD> INCR counter (per article/day)
 *   analytics:article_views:pending                   SET of "<article_id>:<date>" with a counter
 *   analytics:flush:lock                              SET NX EX flush lock
 * The dedupe + SADD + INCR run in ONE Lua script, so they are atomic.
 * FAIL-OPEN: any Redis problem is logged and swallowed; article reads never break.
 */
class ViewRecorder
{
    public const PENDING_KEY = 'analytics:article_views:pending';

    private const RECORD_LUA = <<<'LUA'
if redis.call('SET', KEYS[1], '1', 'NX', 'EX', tonumber(ARGV[1])) then
  redis.call('SADD', KEYS[3], ARGV[2])
  redis.call('INCR', KEYS[2])
  return 1
end
return 0
LUA;

    private ?bool $lastRecorded = null;

    /** True only while the analytics tracker endpoint is dispatching its ArticleViewed event. */
    private bool $trackerContext = false;

    /**
     * Run $dispatch (which fires ArticleViewed) as the browser view-tracker. Django counted
     * ONLY that beacon (it sees the real visitor IP; the article-detail GET is made
     * server-side by Next.js), so by default ArticleViewed events fired anywhere else are
     * not counted (they would double count each page view). Set
     * portal.analytics_tracker.count_detail_events=true (env ANALYTICS_COUNT_DETAIL_EVENTS)
     * to count every ArticleViewed event instead (e.g. if the frontend beacon is removed).
     */
    public function viaTracker(callable $dispatch): void
    {
        $this->trackerContext = true;
        try {
            $dispatch();
        } finally {
            $this->trackerContext = false;
        }
    }

    public static function counterKey(int $articleId, string $date): string
    {
        return "analytics:article_views:{$articleId}:{$date}";
    }

    public static function cooldownKey(int $articleId, string $viewerKey): string
    {
        return "analytics:view_cooldown:{$articleId}:{$viewerKey}";
    }

    /** Stable, non-PII viewer identity: hash of the user id (valid access token) or the client IP. */
    public static function viewerKey(?Request $request = null): string
    {
        $request ??= request();
        $user = ApiUser::resolve($request);
        $identity = $user ? 'user:'.$user->id : 'ip:'.$request->ip();

        return substr(hash('sha256', $identity), 0, 32);
    }

    /** Listener for App\Events\ArticleViewed (registered in AnalyticsAiServiceProvider). */
    public function handle(ArticleViewed $event): void
    {
        if (! $this->trackerContext && ! config('portal.analytics_tracker.count_detail_events', false)) {
            return;
        }
        $this->lastRecorded = false;
        try {
            $this->lastRecorded = $this->record((int) $event->article->id, (string) $event->viewerKey);
        } catch (\Throwable $e) {
            Log::warning('Analytics view recording failed (fail-open): '.$e->getMessage());
        }
    }

    /** Whether the most recent handled event incremented the counter (null before any event). */
    public function lastRecorded(): ?bool
    {
        return $this->lastRecorded;
    }

    public function resetLast(): void
    {
        $this->lastRecorded = null;
    }

    /** Selected driver: `database` or `redis`; anything else is a configuration error (never guessed). */
    public static function driver(): string
    {
        $driver = strtolower((string) config('portal.analytics.driver', 'database'));
        if (! in_array($driver, ['database', 'redis'], true)) {
            throw new \InvalidArgumentException("Unsupported ANALYTICS_DRIVER [{$driver}]; use database or redis.");
        }

        return $driver;
    }

    /** @return bool true iff the counter was incremented (false = within the cooldown) */
    public function record(int $articleId, string $viewerKey): bool
    {
        return self::driver() === 'redis'
            ? $this->recordRedis($articleId, $viewerKey)
            : $this->recordDatabase($articleId, $viewerKey);
    }

    private function recordDatabase(int $articleId, string $viewerKey): bool
    {
        $date = now()->toDateString();
        $cooldown = max(1, (int) config('portal.analytics.view_cooldown_seconds', 30));
        $marker = self::cooldownKey($articleId, $viewerKey);

        // Dedupe: add() succeeds for exactly one caller per cooldown window (atomic on the database
        // store: unique-key insert-or-ignore; file store: exclusive file lock).
        if (! Cache::add($marker, 1, $cooldown)) {
            return false;
        }

        try {
            $now = now();
            DB::statement(
                'INSERT INTO article_daily_views (article_id, `date`, views, created_at, updated_at) VALUES (?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE views = views + 1, updated_at = VALUES(updated_at)',
                [$articleId, $date, $now, $now],
            );
        } catch (\Throwable $e) {
            try {
                Cache::forget($marker);   // the view was not stored: let the viewer's next hit count
            } catch (\Throwable) {
            }
            throw $e;
        }

        return true;
    }

    private function recordRedis(int $articleId, string $viewerKey): bool
    {
        $date = now()->toDateString();
        $cooldown = max(1, (int) config('portal.analytics.view_cooldown_seconds', 30));

        return (int) Redis::connection('analytics')->eval(
            self::RECORD_LUA,
            3,
            self::cooldownKey($articleId, $viewerKey),
            self::counterKey($articleId, $date),
            self::PENDING_KEY,
            $cooldown,
            "{$articleId}:{$date}",
        ) === 1;
    }
}
