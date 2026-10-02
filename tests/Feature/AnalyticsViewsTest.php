<?php

namespace Tests\Feature;

use App\Events\ArticleViewed;
use App\Jobs\FlushArticleViews;
use App\Models\Article;
use App\Models\ArticleDailyView;
use App\Services\Analytics\ViewFlusher;
use App\Services\Analytics\ViewRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ANALYTICS_DRIVER=redis pipeline. Needs a reachable Redis (skipped by --exclude-group=redis); the database driver is
 * covered by AnalyticsDatabaseDriverTest. Uses Redis DB 15 (exclusive to these tests) so it never collides with other DBs.
 */
#[Group('redis')]
class AnalyticsViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.analytics.driver' => 'redis']);
        $this->useRedis(15);
        // Safety: never flush anything but our exclusive DB.
        $this->assertSame(15, $this->redis()->client()->getDbNum());
        $this->redis()->flushdb();
        config([
            'portal.analytics.view_cooldown_seconds' => 30, 'portal.analytics.flush_lock_seconds' => 300,
            'portal.analytics_tracker.count_detail_events' => true,   // direct ArticleViewed dispatches count in these tests
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        try {
            $this->redis()->flushdb();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function useRedis(int $db, int $port = 6379): void
    {
        config(['database.redis.analytics.database' => $db, 'database.redis.analytics.port' => $port]);
        // RedisManager captured the config at boot: rebuild it so the new settings apply.
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
    }

    private function redis()
    {
        return Redis::connection('analytics');
    }

    private function views(Article $a): int
    {
        return (int) ArticleDailyView::where('article_id', $a->id)->sum('views');
    }

    private function recordN(Article $a, int $n, string $prefix = 'v'): void
    {
        for ($i = 0; $i < $n; $i++) {
            ArticleViewed::dispatch($a, "{$prefix}{$i}");
        }
    }

    public function test_listener_is_registered_for_article_viewed(): void
    {
        $this->assertTrue(Event::hasListeners(ArticleViewed::class));
    }

    public function test_by_default_only_the_tracker_beacon_counts_not_other_article_viewed_events(): void
    {
        config(['portal.analytics_tracker.count_detail_events' => false]);
        $a = Article::factory()->published()->create();
        $day = now()->toDateString();

        ArticleViewed::dispatch($a, 'detail-endpoint-viewer');           // e.g. the SSR article-detail GET
        $this->assertNull($this->redis()->get("analytics:article_views:{$a->id}:{$day}"));

        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => true]);
        $this->assertSame('1', $this->redis()->get("analytics:article_views:{$a->id}:{$day}"));
    }

    public function test_cooldown_dedupes_per_viewer_and_counts_per_article_day(): void
    {
        $a = Article::factory()->published()->create();
        $b = Article::factory()->published()->create();
        $day = now()->toDateString();

        ArticleViewed::dispatch($a, 'viewer-1');
        ArticleViewed::dispatch($a, 'viewer-1');     // within cooldown -> ignored
        ArticleViewed::dispatch($a, 'viewer-2');
        ArticleViewed::dispatch($b, 'viewer-1');     // other article: separate cooldown

        $this->assertSame('2', $this->redis()->get("analytics:article_views:{$a->id}:{$day}"));
        $this->assertSame('1', $this->redis()->get("analytics:article_views:{$b->id}:{$day}"));
        $ttl = $this->redis()->ttl("analytics:view_cooldown:{$a->id}:viewer-1");
        $this->assertTrue($ttl > 0 && $ttl <= 30, "ttl={$ttl}");
        $this->assertEqualsCanonicalizing(["{$a->id}:{$day}", "{$b->id}:{$day}"], $this->redis()->smembers('analytics:article_views:pending'));
    }

    public function test_cooldown_expiry_allows_a_new_count(): void
    {
        config(['portal.analytics.view_cooldown_seconds' => 1]);
        $a = Article::factory()->published()->create();
        ArticleViewed::dispatch($a, 'x');
        usleep(1_200_000);
        ArticleViewed::dispatch($a, 'x');
        $this->assertSame('2', $this->redis()->get('analytics:article_views:'.$a->id.':'.now()->toDateString()));
    }

    public function test_flush_moves_exactly_n_and_is_idempotent(): void
    {
        $a = Article::factory()->published()->create();
        $b = Article::factory()->published()->create();
        $this->recordN($a, 7);
        $this->recordN($b, 3);

        $r = app(ViewFlusher::class)->flush();
        $this->assertSame(['skipped' => false, 'flushed_articles' => 2, 'failed_articles' => 0, 'total_views_persisted' => 10], $r);
        $this->assertSame(7, $this->views($a));
        $this->assertSame(3, $this->views($b));
        $this->assertSame(0, (int) $this->redis()->scard('analytics:article_views:pending'));
        $this->assertSame([], $this->redis()->keys('analytics:article_views:*'));
        $this->assertFalse((bool) $this->redis()->exists('analytics:flush:lock'));

        // Re-running flushes nothing: no double counting.
        $this->assertSame(0, app(ViewFlusher::class)->flush()['total_views_persisted']);
        $this->assertSame(7, $this->views($a));

        // New views accumulate onto the same (article, day) row.
        $this->recordN($a, 5, 'later');
        app(ViewFlusher::class)->flush();
        $this->assertSame(12, $this->views($a));
        $this->assertSame(1, ArticleDailyView::where('article_id', $a->id)->count());
    }

    public function test_counters_are_attributed_to_the_day_of_the_view(): void
    {
        $a = Article::factory()->published()->create();
        Carbon::setTestNow(now()->subDay());
        $this->recordN($a, 2, 'y');
        Carbon::setTestNow();
        $this->recordN($a, 3, 't');

        app(ViewFlusher::class)->flush();

        $this->assertSame(2, ArticleDailyView::where('article_id', $a->id)->whereDate('date', now()->subDay()->toDateString())->value('views'));
        $this->assertSame(3, ArticleDailyView::where('article_id', $a->id)->whereDate('date', now()->toDateString())->value('views'));
    }

    public function test_flush_job_runs_the_flusher_and_is_scheduled_every_minute(): void
    {
        $a = Article::factory()->published()->create();
        $this->recordN($a, 4);

        FlushArticleViews::dispatch();   // queue=sync in tests
        $this->assertSame(4, $this->views($a));

        $events = collect(app(Schedule::class)->events());
        $flush = $events->first(fn ($e) => $e->description === 'analytics-flush-views');
        $this->assertNotNull($flush);
        $this->assertSame('* * * * *', $flush->expression);
    }

    public function test_overlapping_flush_is_skipped_by_lock(): void
    {
        $a = Article::factory()->published()->create();
        $this->recordN($a, 3);
        $this->redis()->set(ViewFlusher::LOCK_KEY, 'other-run', 'EX', 60);

        $r = app(ViewFlusher::class)->flush();

        $this->assertTrue($r['skipped']);
        $this->assertSame(0, $this->views($a));
        $this->assertSame('other-run', $this->redis()->get(ViewFlusher::LOCK_KEY));   // not stolen
        $this->assertSame('3', $this->redis()->get('analytics:article_views:'.$a->id.':'.now()->toDateString()));
    }

    public function test_db_failure_restores_counts_and_next_flush_persists_them_once(): void
    {
        $a = Article::factory()->published()->create();
        $ok = Article::factory()->published()->create();
        $this->recordN($a, 6);
        // MySQL DDL would implicitly commit the RefreshDatabase transaction: block the write with an
        // out-of-range UNSIGNED counter instead (strict mode rejects views + 6 > 4294967295).
        DB::table('article_daily_views')->insert(['article_id' => $a->id, 'date' => now()->toDateString(), 'views' => 4294967295, 'created_at' => now(), 'updated_at' => now()]);

        $r = app(ViewFlusher::class)->flush();
        $this->assertSame(1, $r['failed_articles']);
        $this->assertSame(4294967295, $this->views($a));   // untouched: nothing was added
        $day = now()->toDateString();
        $this->assertSame('6', $this->redis()->get("analytics:article_views:{$a->id}:{$day}"));
        $this->assertTrue((bool) $this->redis()->sismember('analytics:article_views:pending', "{$a->id}:{$day}"));

        DB::table('article_daily_views')->where('article_id', $a->id)->update(['views' => 0]);   // unblock
        $this->recordN($ok, 2);
        $r = app(ViewFlusher::class)->flush();
        $this->assertSame(8, $r['total_views_persisted']);
        $this->assertSame(6, $this->views($a));
        $this->assertSame(2, $this->views($ok));
        $this->assertSame(0, app(ViewFlusher::class)->flush()['total_views_persisted']);
        $this->assertSame(6, $this->views($a));
    }

    public function test_counters_for_deleted_articles_and_legacy_members_are_handled(): void
    {
        $a = Article::factory()->published()->create();
        $day = now()->toDateString();
        // deleted article
        $this->redis()->set("analytics:article_views:999999:{$day}", 5);
        $this->redis()->sadd('analytics:article_views:pending', "999999:{$day}");
        // legacy Django counter (plain id member + un-dated key) is attributed to today
        $this->redis()->set("analytics:article_views:{$a->id}", 9);
        $this->redis()->sadd('analytics:article_views:pending', (string) $a->id);
        // garbage member
        $this->redis()->sadd('analytics:article_views:pending', 'junk');

        $r = app(ViewFlusher::class)->flush();

        $this->assertSame(0, $r['failed_articles']);
        $this->assertSame(9, $this->views($a));
        $this->assertSame(0, ArticleDailyView::where('article_id', 999999)->count());
        $this->assertSame(0, (int) $this->redis()->scard('analytics:article_views:pending'));
    }

    public function test_redis_down_is_fail_open_for_recording_and_endpoint(): void
    {
        $a = Article::factory()->published()->create();
        $this->useRedis(15, port: 1);   // nothing listens here

        ArticleViewed::dispatch($a, 'v');   // must not throw
        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => false]);

        // and the flush job never throws either
        FlushArticleViews::dispatch();
        $this->assertTrue(true);
    }

    public function test_view_endpoint_dispatches_only_article_viewed_and_records_once_per_cooldown(): void
    {
        $a = Article::factory()->published()->create();

        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => true]);
        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => false]);
        $this->assertSame('1', $this->redis()->get('analytics:article_views:'.$a->id.':'.now()->toDateString()));

        Event::fake([ArticleViewed::class]);
        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202);
        Event::assertDispatchedTimes(ArticleViewed::class, 1);
        Event::assertDispatched(ArticleViewed::class, fn ($e) => $e->article->is($a) && preg_match('/^[0-9a-f]{32}$/', $e->viewerKey));
    }

    public function test_view_endpoint_404s_for_unpublished_or_unknown_and_records_nothing(): void
    {
        $draft = Article::factory()->create();
        Event::fake([ArticleViewed::class]);

        $this->postJson("/api/analytics/articles/{$draft->slug}/view/")->assertNotFound()->assertJson(['detail' => 'Not found.']);
        $this->postJson('/api/analytics/articles/nope/view/')->assertNotFound();
        Event::assertNotDispatched(ArticleViewed::class);
        $this->assertSame([], $this->redis()->keys('analytics:*'));
    }

    public function test_viewer_key_is_stable_non_pii_hash_and_differs_by_ip(): void
    {
        $r1 = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);
        $r2 = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '203.0.113.6']);
        $k1 = ViewRecorder::viewerKey($r1);
        $this->assertSame($k1, ViewRecorder::viewerKey($r1));
        $this->assertNotSame($k1, ViewRecorder::viewerKey($r2));
        $this->assertStringNotContainsString('203.0.113.5', $k1);
    }
}
