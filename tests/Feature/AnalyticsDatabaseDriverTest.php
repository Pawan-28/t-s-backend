<?php

namespace Tests\Feature;

use App\Events\ArticleViewed;
use App\Jobs\FlushArticleViews;
use App\Models\Article;
use App\Models\ArticleDailyView;
use App\Models\User;
use App\Services\Analytics\ViewFlusher;
use App\Services\Analytics\ViewRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ANALYTICS_DRIVER=database (shared-hosting profile): the same scenarios as AnalyticsViewsTest (Redis driver), but with
 * NO Redis anywhere. Dedupe runs through the `database` cache store, counting is one atomic upsert straight into
 * article_daily_views. Redis is pointed at a dead port to prove nothing touches it.
 */
class AnalyticsDatabaseDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.analytics.driver' => 'database',
            'portal.analytics.view_cooldown_seconds' => 30,
            'portal.analytics_tracker.count_detail_events' => true,
            'cache.default' => 'database',
            'database.redis.default.port' => 1, 'database.redis.cache.port' => 1, 'database.redis.analytics.port' => 1,
        ]);
        Cache::forget('x');   // resolve the database store now
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    public function test_default_driver_is_database_and_uses_the_database_cache_store(): void
    {
        $this->assertSame('database', ViewRecorder::driver());
        $this->assertSame('database', config('cache.stores.database.driver'));
        $this->assertSame('database', config('cache.default'));
    }

    public function test_unknown_driver_is_a_loud_configuration_error_not_a_guess(): void
    {
        config(['portal.analytics.driver' => 'memcached']);
        $this->expectException(\InvalidArgumentException::class);
        ViewRecorder::driver();
    }

    public function test_by_default_only_the_tracker_beacon_counts_not_other_article_viewed_events(): void
    {
        config(['portal.analytics_tracker.count_detail_events' => false]);
        $a = Article::factory()->published()->create();

        ArticleViewed::dispatch($a, 'detail-endpoint-viewer');
        $this->assertSame(0, $this->views($a));

        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => true]);
        $this->assertSame(1, $this->views($a));
    }

    public function test_cooldown_dedupes_per_viewer_and_counts_per_article_day(): void
    {
        $a = Article::factory()->published()->create();
        $b = Article::factory()->published()->create();

        ArticleViewed::dispatch($a, 'viewer-1');
        ArticleViewed::dispatch($a, 'viewer-1');     // within cooldown -> ignored
        ArticleViewed::dispatch($a, 'viewer-2');
        ArticleViewed::dispatch($b, 'viewer-1');     // other article: separate cooldown

        $this->assertSame(2, $this->views($a));
        $this->assertSame(1, $this->views($b));
        $this->assertSame(1, ArticleDailyView::where('article_id', $a->id)->count());
        $ttl = (int) DB::table('cache')->where('key', 'like', '%analytics:view_cooldown:'.$a->id.':viewer-1')->value('expiration') - time();
        $this->assertTrue($ttl > 0 && $ttl <= 30, "ttl={$ttl}");
    }

    public function test_cooldown_marker_holds_no_pii_only_the_viewer_hash(): void
    {
        $a = Article::factory()->published()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202);

        $keys = DB::table('cache')->where('key', 'like', '%analytics:view_cooldown:%')->pluck('key')->implode(',');
        $this->assertNotSame('', $keys);
        $this->assertStringNotContainsString('203.0.113.77', $keys);
        $this->assertMatchesRegularExpression('/analytics:view_cooldown:'.$a->id.':[0-9a-f]{32}/', $keys);
    }

    public function test_cooldown_expiry_allows_a_new_count(): void
    {
        $a = Article::factory()->published()->create();
        ArticleViewed::dispatch($a, 'x');
        ArticleViewed::dispatch($a, 'x');
        $this->assertSame(1, $this->views($a));

        Carbon::setTestNow(now()->addSeconds(31));   // the database cache store honours Carbon's clock: the marker expires
        ArticleViewed::dispatch($a, 'x');
        $this->assertSame(2, $this->views($a));
    }

    public function test_counts_are_attributed_to_the_day_of_the_view(): void
    {
        $a = Article::factory()->published()->create();
        Carbon::setTestNow(now()->subDay());
        $this->recordN($a, 2, 'y');
        Carbon::setTestNow();
        $this->recordN($a, 3, 't');

        $this->assertSame(2, (int) ArticleDailyView::where('article_id', $a->id)->whereDate('date', now()->subDay()->toDateString())->value('views'));
        $this->assertSame(3, (int) ArticleDailyView::where('article_id', $a->id)->whereDate('date', now()->toDateString())->value('views'));
        $this->assertSame(2, ArticleDailyView::where('article_id', $a->id)->count());
    }

    public function test_views_are_durable_immediately_and_flush_is_a_no_op_that_never_double_counts(): void
    {
        $a = Article::factory()->published()->create();
        $this->recordN($a, 7);
        $this->assertSame(7, $this->views($a));   // visible without any flush

        $zero = ['skipped' => false, 'flushed_articles' => 0, 'failed_articles' => 0, 'total_views_persisted' => 0];
        $this->assertSame($zero, app(ViewFlusher::class)->flush());
        FlushArticleViews::dispatch();   // queue=sync in tests
        $this->artisan('analytics:flush')->expectsOutputToContain('nothing to flush')->assertExitCode(0);
        $this->assertSame(7, $this->views($a));

        $this->recordN($a, 5, 'later');
        app(ViewFlusher::class)->flush();
        $this->assertSame(12, $this->views($a));
        $this->assertSame(1, ArticleDailyView::where('article_id', $a->id)->count());
    }

    public function test_the_redis_flush_job_is_only_scheduled_for_the_redis_driver(): void
    {
        $flush = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'analytics-flush-views');
        $this->assertNotNull($flush);
        $this->assertSame('* * * * *', $flush->expression);
        $this->assertFalse($flush->filtersPass($this->app), 'flush must not be dispatched with the database driver');
        config(['portal.analytics.driver' => 'redis']);
        $this->assertTrue($flush->filtersPass($this->app));
    }

    public function test_cache_failure_is_fail_open_for_recording_and_endpoint(): void
    {
        $a = Article::factory()->published()->create();
        config(['cache.default' => 'no_such_store']);

        ArticleViewed::dispatch($a, 'v');   // must not throw
        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => false]);
        $this->assertSame(0, $this->views($a));
    }

    public function test_counter_write_failure_is_fail_open_and_the_next_hit_still_counts(): void
    {
        $a = Article::factory()->published()->create();
        $ok = Article::factory()->published()->create();
        // Break ONLY the article_daily_views write: the FK to a deleted article makes the upsert fail.
        $gone = new Article(['id' => 987654]);
        $gone->id = 987654;

        ArticleViewed::dispatch($gone, 'viewer');   // FK violation inside the recorder -> logged, swallowed
        $this->assertSame(0, ArticleDailyView::count());

        // The dedupe marker was rolled back, so the very same viewer is not locked out for the cooldown.
        $this->assertNull(Cache::get(ViewRecorder::cooldownKey(987654, 'viewer')));
        ArticleViewed::dispatch($ok, 'viewer');
        $this->assertSame(1, $this->views($ok));
        $this->assertSame(0, $this->views($a));
    }

    public function test_view_endpoint_dispatches_only_article_viewed_and_records_once_per_cooldown(): void
    {
        $a = Article::factory()->published()->create();

        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => true]);
        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202)->assertExactJson(['recorded' => false]);
        $this->assertSame(1, $this->views($a));

        Event::fake([ArticleViewed::class]);
        $this->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202);
        Event::assertDispatchedTimes(ArticleViewed::class, 1);
    }

    public function test_view_endpoint_404s_for_unpublished_or_unknown_and_records_nothing(): void
    {
        $draft = Article::factory()->create();
        $this->postJson("/api/analytics/articles/{$draft->slug}/view/")->assertNotFound()->assertJson(['detail' => 'Not found.']);
        $this->postJson('/api/analytics/articles/nope/view/')->assertNotFound();
        $this->assertSame(0, ArticleDailyView::count());
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%analytics:%')->count());
    }

    public function test_admin_endpoints_aggregate_the_database_driver_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Article::factory()->published()->create();
        $b = Article::factory()->published()->create();
        $draft = Article::factory()->create();   // unpublished: excluded from reports
        $this->recordN($a, 5, 'a');
        $this->recordN($b, 2, 'b');
        Carbon::setTestNow(now()->subDay());
        $this->recordN($a, 3, 'old');
        Carbon::setTestNow();
        DB::table('article_daily_views')->insert(['article_id' => $draft->id, 'date' => now()->toDateString(), 'views' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAsUser($admin);
        $this->getJson('/api/analytics/overview/')->assertOk()->assertExactJson(['total_views' => 10]);
        $popular = $this->getJson('/api/analytics/articles/popular/')->assertOk()->json();
        $this->assertSame([$a->id, $b->id], array_column($popular, 'id'));
        $this->assertSame([8, 2], array_column($popular, 'total_views'));
        $series = $this->getJson('/api/analytics/views-over-time/')->assertOk()->json();
        $byDate = array_column($series, 'views', 'date');
        $this->assertSame(7, $byDate[now()->toDateString()]);
        $this->assertSame(3, $byDate[now()->subDay()->toDateString()]);
        $rows = $this->getJson('/api/analytics/admin/article-daily-views/')->assertOk()->json();
        $this->assertSame(4, $rows['count']);
    }
}
