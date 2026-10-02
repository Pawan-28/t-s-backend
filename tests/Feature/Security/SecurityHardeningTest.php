<?php

namespace Tests\Feature\Security;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/** Security regression suite: route classification, tokens, mass assignment, headers, search oracle, throttles. */
class SecurityHardeningTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    private const PW = 'Sup3r-Secret-pass';

    /** Endpoints that are intentionally reachable without an access token (authorisation is in-controller or signature based). */
    private const PUBLIC = [
        'GET api/health', 'GET api/advertisements/active', 'GET api/articles', 'GET api/articles/{slug}',
        'GET api/articles/{slug}/images', 'GET api/articles/{slug}/images/{image}', 'GET api/articles/{slug}/related',
        'GET api/categories', 'GET api/categories/{slug}', 'GET api/industries', 'GET api/industries/{slug}',
        'GET api/subcategories', 'GET api/subcategories/{id}', 'GET api/tags', 'GET api/tags/{slug}',
        'GET api/search', 'GET api/subscriptions/plans',
        'POST api/auth/login', 'POST api/auth/register', 'POST api/auth/refresh',
        'POST api/analytics/articles/{slug}/view', 'POST api/subscriptions/webhook',
        'POST api/ai/plagiarism-webhook/{token}/{scanId}/{status?}',
    ];

    /** @return list<array{0:string,1:string,2:LaravelRoute}> [method, uri, route] for every API route */
    private function apiRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->methods() as $m) {
                if ($m !== 'HEAD') {
                    $out[] = [$m, $route->uri(), $route];
                }
            }
        }

        return $out;
    }

    private function concrete(string $uri): string
    {
        return '/'.preg_replace(['/\{[a-zA-Z]+\?\}/', '/\{[a-zA-Z]+\}/'], ['', '1'], rtrim($uri, '/'));
    }

    public function test_every_write_route_and_every_non_listed_read_route_requires_authentication(): void
    {
        $seen = 0;
        foreach ($this->apiRoutes() as [$method, $uri, $route]) {
            $key = $method.' '.$uri;
            $mw = array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
            $authed = in_array('api.auth', $mw, true) || in_array('auth:sanctum', $mw, true);
            if (in_array($key, self::PUBLIC, true)) {
                $seen++;
                $this->assertFalse($authed && ! str_contains($key, 'images/{image}'), "$key is listed public but is gated");

                continue;
            }
            $this->assertTrue($authed, "Unclassified route without authentication: $key (add api.auth or list it as public deliberately)");
            $this->app['auth']->forgetGuards();
            $this->json($method, $this->concrete($uri))->assertStatus(401);
        }
        $this->assertSame(count(self::PUBLIC), $seen, 'PUBLIC allow-list contains routes that no longer exist');
    }

    public function test_admin_routes_reject_every_non_admin_role(): void
    {
        $roles = [Role::USER, Role::SUBSCRIBER, Role::REPORTER];
        $checked = 0;
        foreach ($this->apiRoutes() as [$method, $uri, $route]) {
            $mw = array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware());
            // Admin-only routes: role:ADMIN, or a feature permission (perm:*) that no role holds by default.
            $gated = array_intersect(['role:ADMIN', 'App\Http\Middleware\RequireRole:ADMIN'], $mw)
                || array_filter($mw, fn ($m) => str_starts_with($m, 'perm:'));
            if (! $gated) {
                continue;
            }
            foreach ($roles as $role) {
                $u = User::factory()->create(['role' => $role]);
                $this->as($u)->json($method, $this->concrete($uri))->assertStatus(403);
            }
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
    }

    // ------------------------------------------------------------------ tokens

    public function test_refresh_token_cannot_call_the_api_and_access_token_cannot_refresh(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->assertOk()->json();

        $this->app['auth']->forgetGuards();
        $this->withToken($login['refresh'])->getJson('/api/auth/me/')->assertStatus(401);
        // ... also on personalising public endpoints: a refresh token is never a login
        $this->app['auth']->forgetGuards();
        $this->withToken($login['refresh'])->getJson('/api/subscriptions/me/')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->as(null)->postJson('/api/auth/refresh/', ['refresh' => $login['access']])->assertStatus(401);
        $this->postJson('/api/auth/refresh/', ['refresh' => ['x']])->assertStatus(401);
        $this->postJson('/api/auth/refresh/', [])->assertStatus(401);
        $this->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertOk()->assertJsonStructure(['access']);
    }

    public function test_token_lifetimes_are_enforced_and_tokens_are_hashed_at_rest(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->json();

        $rows = DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(64, strlen($row->token));
            $this->assertStringNotContainsString(explode('|', $login['access'])[1], $row->token);
            $this->assertStringNotContainsString(explode('|', $login['refresh'])[1], $row->token);
        }
        $access = $rows->firstWhere('name', 'access');
        $refresh = $rows->firstWhere('name', 'refresh');
        $this->assertEqualsWithDelta(15 * 60, strtotime($access->expires_at) - time(), 30);
        $this->assertEqualsWithDelta(7 * 86400, strtotime($refresh->expires_at) - time(), 30);

        // 16 minutes later the access token is dead; the refresh token still works
        $this->travel(16)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($login['access'])->getJson('/api/auth/me/')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->as(null)->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertOk();
        // 8 days later even the refresh token is dead
        $this->travel(8)->days();
        $this->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertStatus(401);
    }

    public function test_deactivated_user_loses_access_immediately_and_cannot_refresh(): void
    {
        $u = User::factory()->create();
        $login = $this->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->json();
        $u->forceFill(['is_active' => false])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($login['access'])->getJson('/api/auth/me/')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->as(null)->postJson('/api/auth/refresh/', ['refresh' => $login['refresh']])->assertStatus(401);
    }

    public function test_logout_revokes_both_tokens_and_needs_own_refresh_token(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $la = $this->postJson('/api/auth/login/', ['email' => $a->email, 'password' => self::PW])->json();
        $this->app['auth']->forgetGuards();
        $lb = $this->postJson('/api/auth/login/', ['email' => $b->email, 'password' => self::PW])->json();

        // someone else's refresh token cannot be used to log out / revoke
        $this->app['auth']->forgetGuards();
        $this->withToken($la['access'])->postJson('/api/auth/logout/', ['refresh' => $lb['refresh']])->assertStatus(400);
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $b->id)->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($la['access'])->postJson('/api/auth/logout/', ['refresh' => $la['refresh']])->assertStatus(205);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $a->id)->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($la['access'])->getJson('/api/auth/me/')->assertStatus(401);
    }

    public function test_login_gives_the_same_answer_for_unknown_wrong_password_and_inactive(): void
    {
        $known = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $bodies = [
            $this->postJson('/api/auth/login/', ['email' => 'nobody@example.com', 'password' => self::PW])->assertStatus(401)->json(),
            $this->postJson('/api/auth/login/', ['email' => $known->email, 'password' => 'wrong-pass-1'])->assertStatus(401)->json(),
            $this->postJson('/api/auth/login/', ['email' => $inactive->email, 'password' => self::PW])->assertStatus(401)->json(),
        ];
        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)));
        $this->postJson('/api/auth/login/', ['email' => $known->email, 'password' => str_repeat('a', 5000)])->assertStatus(400);
    }

    // ------------------------------------------------------------------ mass assignment

    public function test_register_cannot_set_privileged_columns(): void
    {
        $this->postJson('/api/auth/register/', [
            'email' => 'evil@example.com', 'password' => self::PW, 'password2' => self::PW,
            'role' => 'ADMIN', 'is_active' => false, 'is_staff' => true, 'is_superuser' => true,
            'phone_verified_at' => now()->toIso8601String(), 'id' => 999, 'last_login' => now()->toIso8601String(),
        ])->assertStatus(201);
        $u = User::query()->where('email', 'evil@example.com')->firstOrFail();
        $this->assertSame(Role::USER, $u->role);
        $this->assertTrue($u->is_active);
        $this->assertNull($u->phone_verified_at);
        $this->assertNull($u->last_login);
        $this->assertNotSame(999, $u->id);
    }

    public function test_user_model_does_not_mass_assign_role_or_state(): void
    {
        $u = new User(['email' => 'x@example.com', 'role' => 'ADMIN', 'is_active' => false, 'phone_verified_at' => now(), 'id' => 5]);
        $this->assertNull($u->role);
        $this->assertNull($u->id);
        $this->assertNull($u->phone_verified_at);
        $this->assertSame('x@example.com', $u->email);
    }

    public function test_admin_user_patch_ignores_protected_columns_and_validates_role(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['email' => 'target@example.com']);
        $this->as($admin)->patchJson("/api/accounts/users/{$target->id}/", [
            'id' => 999999, 'last_login' => '2000-01-01 00:00:00', 'phone_verified_at' => '2000-01-01 00:00:00', 'role' => 'REPORTER',
        ])->assertOk()->assertJsonPath('role', 'REPORTER');
        $target->refresh();
        $this->assertNotSame(999999, $target->id);
        $this->assertNull($target->last_login);
        $this->assertNull($target->phone_verified_at);
        $this->as($admin)->patchJson("/api/accounts/users/{$target->id}/", ['role' => 'SUPERUSER'])->assertStatus(400);
    }

    // ------------------------------------------------------------------ headers / CORS

    public function test_api_responses_carry_security_headers_and_authenticated_ones_are_not_cacheable(): void
    {
        $r = $this->as(null)->getJson('/api/articles/')->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
        $this->assertStringNotContainsString('no-store', (string) $r->headers->get('Cache-Control'));

        $u = User::factory()->create();
        $r = $this->as($u)->getJson('/api/auth/me/')->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('Authorization', (string) $r->headers->get('Vary'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        // login (tokens in the body) and error responses too
        $r = $this->as(null)->postJson('/api/auth/login/', ['email' => $u->email, 'password' => self::PW])->assertOk();
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $r = $this->as($u)->getJson('/api/accounts/users/')->assertStatus(403);
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_cors_is_an_allow_list_without_credentials(): void
    {
        $ok = $this->withHeaders(['Origin' => 'http://localhost:3000'])->getJson('/api/articles/');
        $this->assertSame('http://localhost:3000', $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($ok->headers->get('Access-Control-Allow-Credentials'));
        $evil = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/articles/');
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    // ------------------------------------------------------------------ throttles

    public function test_a_bogus_bearer_token_does_not_lift_the_anonymous_rate_limit(): void
    {
        config(['portal.throttle.anon.rate' => '3/minute', 'portal.throttle.user.rate' => '1000/minute']);
        $this->app['auth']->forgetGuards();
        $this->withToken('999|not-a-real-token');
        foreach (range(1, 3) as $_) {
            $this->getJson('/api/articles/')->assertOk();
        }
        $this->getJson('/api/articles/')->assertStatus(429);
    }

    // ------------------------------------------------------------------ restricted-content oracles

    private function lockedArticle(string $level, string $needle = 'zebracornflakes'): Article
    {
        return Article::factory()->published()->create([
            'title' => 'Locked story', 'excerpt' => 'teaser', 'access_level' => $level,
            'content' => "<p>secret body mentioning {$needle} deep inside</p>",
            'subcategory_id' => $this->tree()['subcategory']->id,
        ]);
    }

    public function test_restricted_bodies_cannot_be_probed_through_search_or_list_search(): void
    {
        $locked = $this->lockedArticle(AccessLevel::SUBSCRIBER_ONLY->value);
        $public = Article::factory()->published()->create([
            'title' => 'Open story', 'content' => '<p>open body mentioning zebracornflakes too</p>',
            'subcategory_id' => $this->tree()['subcategory']->id,
        ]);
        $reporter = User::factory()->reporter()->create();
        $plain = User::factory()->create();
        $sub = $this->subscriberWithPlan();
        $admin = User::factory()->admin()->create();

        foreach ([null, $plain, $reporter] as $caller) {
            $this->assertSame(['Open story'], array_column($this->as($caller)->getJson('/api/search/?q=zebracornflakes')->assertOk()->json('results'), 'title'));
            $this->assertSame(['Open story'], array_column($this->as($caller)->getJson('/api/articles/?search=zebracornflakes')->assertOk()->json('results'), 'title'));
            $this->assertSame(['Open story'], array_column($this->as($caller)->getJson('/api/articles/?search=cornflake')->assertOk()->json('results'), 'title'));
        }
        // title / excerpt of a locked article stay searchable for everybody
        $this->assertSame(['Locked story'], array_column($this->as(null)->getJson('/api/search/?q=teaser')->assertOk()->json('results'), 'title'));
        $this->assertSame(['Locked story'], array_column($this->as(null)->getJson('/api/articles/?search=teaser')->assertOk()->json('results'), 'title'));
        // entitled callers can still find the body through the list search
        foreach ([$sub, $admin] as $caller) {
            $this->assertEqualsCanonicalizing(['Locked story', 'Open story'], array_column($this->as($caller)->getJson('/api/articles/?search=cornflake')->assertOk()->json('results'), 'title'));
        }
        // ... and nobody ever gets the body itself
        $this->assertNull($this->as(null)->getJson("/api/articles/{$locked->slug}/")->assertOk()->json('content'));
    }

    public function test_changing_access_level_reindexes_the_search_vector(): void
    {
        $a = $this->lockedArticle(AccessLevel::PUBLIC->value);
        $this->assertCount(1, $this->as(null)->getJson('/api/search/?q=zebracornflakes')->json('results'));
        $a->update(['access_level' => AccessLevel::SUBSCRIBER_ONLY]);
        $this->assertCount(0, $this->as(null)->getJson('/api/search/?q=zebracornflakes')->json('results'));
        $a->update(['access_level' => AccessLevel::PUBLIC]);
        $this->assertCount(1, $this->as(null)->getJson('/api/search/?q=zebracornflakes')->json('results'));
    }

    public function test_locked_bodies_never_appear_in_any_other_payload(): void
    {
        $locked = $this->lockedArticle(AccessLevel::RESTRICTED->value, 'zebracornflakes');
        $reporter = $locked->author;
        $sibling = Article::factory()->published()->create(['subcategory_id' => $locked->subcategory_id]);
        $stranger = User::factory()->create();

        $urls = [
            '/api/articles/', "/api/articles/{$sibling->slug}/related/", '/api/search/?q=locked',
            "/api/articles/{$locked->slug}/images/", '/api/analytics/articles/popular/',
            '/api/ai/analysis-results/', '/api/ai/plagiarism-results/', '/api/notifications/admin/',
        ];
        foreach ([null, $stranger, $reporter] as $caller) {
            foreach ($urls as $url) {
                $res = $this->as($caller)->getJson($url);
                if ($res->status() === 200) {
                    $this->assertStringNotContainsString('zebracornflakes', $res->getContent(), "body leaked on $url");
                }
            }
        }
    }

    // ------------------------------------------------------------------ time zone round trip

    public function test_timestamps_round_trip_with_the_asia_kolkata_offset(): void
    {
        // Asia/Kolkata (+05:30) by default; the same assertions hold for APP_TIMEZONE=UTC etc. (derived, not hard-coded).
        $tz = config('app.timezone');
        $instant = CarbonImmutable::parse('2026-03-01 18:45:00', 'UTC'); // = 2026-03-02 00:15 IST
        $local = $instant->setTimezone($tz);
        $this->assertSame($local->format('P'), config('database.connections.mysql.timezone'));
        $this->assertSame($local->format('P'), DB::selectOne('select @@session.time_zone as z')->z);
        Carbon::setTestNow($instant);
        try {
            $a = Article::factory()->published()->create(['published_at' => now(), 'title' => 'Tz story']);

            // stored value: DATETIME columns hold app-zone wall-clock time
            $stored = DB::selectOne('select published_at as t from articles where id = ?', [$a->id])->t;
            $this->assertSame($local->format('Y-m-d H:i:s'), $stored);
            if ($tz === 'Asia/Kolkata') {
                $this->assertSame('2026-03-02 00:15:00', $stored);
            }

            // API string: app-zone wall clock with its offset, same instant
            $iso = $this->as(null)->getJson("/api/articles/{$a->slug}/")->assertOk()->json('published_at');
            $this->assertStringEndsWith($local->format('P'), $iso);
            $this->assertSame($local->format('Y-m-d\TH:i:sP'), $iso);
            $this->assertSame($instant->getTimestamp(), Carbon::parse($iso)->getTimestamp());

            // re-hydrated model keeps the instant
            $this->assertSame($instant->getTimestamp(), $a->fresh()->published_at->getTimestamp());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_client_supplied_schedule_offsets_are_honoured_and_naive_times_are_read_as_kolkata(): void
    {
        // "naive" (zone-less) input is read in the PROJECT zone (Asia/Kolkata by default, like Django/DRF).
        $admin = User::factory()->admin()->create();
        $a = Article::factory()->status(ArticleStatus::APPROVED)->create();
        $future = now()->addDays(3);

        $naive = $future->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:s');
        $this->as($admin)->postJson("/api/articles/{$a->slug}/schedule/", ['scheduled_for' => $naive])->assertOk();
        $this->assertEqualsWithDelta($future->getTimestamp(), $a->fresh()->scheduled_publish_at->getTimestamp(), 1);

        $utc = $future->copy()->addHour()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->as($admin)->postJson("/api/articles/{$a->slug}/schedule/", ['scheduled_for' => $utc])->assertOk();
        $this->assertEqualsWithDelta($future->copy()->addHour()->getTimestamp(), $a->fresh()->scheduled_publish_at->getTimestamp(), 1);
    }

    public function test_unexpected_errors_render_a_generic_500_without_internals(): void
    {
        config(['app.debug' => false, 'logging.default' => 'null']);
        Route::middleware('api')->prefix('api')->get('_boom', fn () => throw new \RuntimeException('SQLSTATE[08006] password=hunter2 host=10.0.0.5'));

        $r = $this->getJson('/api/_boom');
        $r->assertStatus(500)->assertExactJson(['detail' => 'Server error.']);
        $this->assertStringNotContainsString('hunter2', $r->getContent());
        $this->assertStringNotContainsString('RuntimeException', $r->getContent());
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }
}
