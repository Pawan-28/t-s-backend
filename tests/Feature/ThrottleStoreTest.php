<?php

namespace Tests\Feature;

use App\Http\Middleware\ScopeThrottle;
use App\Models\Article;
use App\Models\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rate limiting must work on the configured cache store (database here: the shared-hosting profile, no Redis),
 * keep the same numbers/scopes, and FAIL OPEN when that store is broken (throttle.scope AND named limiters).
 */
class ThrottleStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'database', 'database.redis.default.port' => 1, 'database.redis.cache.port' => 1]);
        $this->useLimiterStore('database');
    }

    /**
     * The RateLimiter singleton is built at boot (AnalyticsAiServiceProvider registers the named limiters on it),
     * i.e. before a test can change config. Re-point ONLY its cache store; keep its registered limiters.
     */
    private function useLimiterStore(string $store): void
    {
        $limiter = app(CacheRateLimiter::class);
        (new \ReflectionProperty($limiter, 'cache'))->setValue($limiter, Cache::store($store));
    }

    private function breakTheLimiterStore(): void
    {
        config(['cache.stores.broken' => ['driver' => 'database', 'table' => 'no_such_cache', 'lock_table' => 'no_such_locks']]);
        $this->useLimiterStore('broken');
    }

    public function test_throttle_scope_counts_in_the_database_cache_store_with_the_configured_numbers(): void
    {
        config(['portal.throttle.anon.rate' => '3/minute']);
        foreach (range(1, 3) as $_) {
            $this->getJson('/api/articles/')->assertOk();
        }
        $this->getJson('/api/articles/')->assertStatus(429)->assertJsonStructure(['detail']);
        $this->assertGreaterThan(0, DB::table('cache')->where('key', 'like', '%throttle:anon:%')->count(), 'counters must live in the cache table');
    }

    public function test_scopes_are_independent_per_identity(): void
    {
        config(['portal.throttle.article_view.rate' => '2/minute']);
        $a = Article::factory()->published()->create();
        foreach (range(1, 2) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->postJson("/api/analytics/articles/{$a->slug}/view/")->assertStatus(202);
    }

    public function test_named_limiters_use_the_database_cache_store(): void
    {
        Route::middleware('throttle:ai-check')->post('/_t/ai', fn () => response()->json(['ok' => true]));
        config(['portal.ai_checks.ai_per_minute' => 2]);
        $user = User::factory()->create();
        $this->actingAsUser($user);
        $this->postJson('/_t/ai')->assertOk()->assertHeader('X-RateLimit-Limit', 2);
        $this->postJson('/_t/ai')->assertOk()->assertHeader('X-RateLimit-Remaining', 0);
        $this->postJson('/_t/ai')->assertStatus(429);
        $this->assertGreaterThan(0, DB::table('cache')->count());
        // another user has an independent budget; GET is not limited at all (Limit::none)
        $this->actingAsUser(User::factory()->create())->postJson('/_t/ai')->assertOk();
    }

    public function test_throttle_scope_fails_open_when_the_cache_store_is_broken(): void
    {
        $this->breakTheLimiterStore();
        Log::spy();
        config(['portal.throttle.anon.rate' => '1/minute']);
        foreach (range(1, 3) as $_) {
            $this->getJson('/api/articles/')->assertOk();
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'failing open'))->atLeast()->once();
    }

    public function test_named_limiter_fails_open_when_the_cache_store_is_broken_but_controller_errors_still_surface(): void
    {
        Route::middleware('throttle:ai-check')->post('/_t/ai', fn () => response()->json(['ok' => true]));
        Route::middleware('throttle:ai-check')->post('/_t/boom', fn () => throw new \DomainException('controller failure'));
        config(['portal.ai_checks.ai_per_minute' => 1]);
        $this->breakTheLimiterStore();
        $this->actingAsUser(User::factory()->create());

        foreach (range(1, 3) as $_) {
            $this->postJson('/_t/ai')->assertOk();
        }
        // only the limiter's own cache calls are guarded, not the downstream application
        $this->postJson('/_t/boom')->assertStatus(500);
    }

    public function test_cache_add_is_a_true_mutex_and_hit_counts_exactly_on_the_database_store(): void
    {
        $store = Cache::store('database');
        $this->assertTrue($store->add('atomic:k', 1, 30));
        $this->assertFalse($store->add('atomic:k', 1, 30));   // insert-or-ignore on the unique key
        $limiter = app(CacheRateLimiter::class);
        $counts = [];
        for ($i = 0; $i < 5; $i++) {
            $counts[] = $limiter->hit('atomic:hits', 60);
        }
        $this->assertSame([1, 2, 3, 4, 5], $counts);
        [$max, $decay] = ScopeThrottle::parse('20/minute');
        $this->assertSame([20, 60], [$max, $decay]);
    }

    public function test_scheduler_locks_work_on_the_database_store(): void
    {
        $lock = Cache::store('database')->lock('schedule:test-lock', 10);
        $this->assertTrue($lock->get());
        $this->assertFalse(Cache::store('database')->lock('schedule:test-lock', 10)->get());
        $lock->release();
        $this->assertTrue(Cache::store('database')->lock('schedule:test-lock', 10)->get());
    }
}
