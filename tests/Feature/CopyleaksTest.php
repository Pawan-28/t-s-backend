<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\PlagiarismCheckResult;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CopyleaksTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wh-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.copyleaks.email' => 'ops@example.com',
            'portal.copyleaks.api_key' => 'cl-key-secret',
            'portal.copyleaks.webhook_base_url' => 'https://api.example.com/',
            'portal.copyleaks.webhook_secret' => self::SECRET,
            'portal.copyleaks.sandbox' => true,
            'portal.copyleaks.pending_timeout_hours' => 24,
        ]);
        Cache::forget('copyleaks:access_token');
    }

    private function fakeCopyleaks(int $submitStatus = 201, ?array $report = null): void
    {
        Http::fake([
            'id.copyleaks.com/*' => Http::response(['access_token' => 'tok-abc', '.expires' => now()->addHours(48)->toIso8601String()]),
            'api.copyleaks.com/v3/scans/submit/file/*' => Http::response('', $submitStatus),
            'api.copyleaks.com/v3.4/downloads/*' => Http::response($report ?? self::report()),
        ]);
    }

    private static function report(): array
    {
        return ['results' => [
            'score' => ['aggregatedScore' => 37.5, 'identicalWords' => 10],
            'internet' => [['url' => 'https://src.example/a', 'title' => 'Source A', 'matchedWords' => ['all' => 42]]],
            'database' => [['title' => 'DB doc', 'introduction' => ['similarity' => 12.5]]],
            'batch' => [],
        ]];
    }

    private function pending(?Article $article = null, array $attrs = []): PlagiarismCheckResult
    {
        return PlagiarismCheckResult::create($attrs + [
            'article_id' => ($article ?? Article::factory()->create())->id,
            'provider' => 'COPYLEAKS', 'scan_id' => bin2hex(random_bytes(16)), 'status' => 'PENDING', 'matches' => [],
        ]);
    }

    private function hook(string $scanId, array $payload = [], ?string $status = 'completed', string $token = self::SECRET)
    {
        return $this->postJson("/api/ai/plagiarism-webhook/{$token}/{$scanId}".($status ? "/{$status}" : ''), $payload);
    }

    public function test_submit_creates_pending_row_and_sends_scan_with_secret_webhook_url(): void
    {
        $this->fakeCopyleaks();
        $reporter = User::factory()->reporter()->create();
        $article = Article::factory()->create(['author_id' => $reporter->id, 'content' => '<p>Hello &amp; world of news</p>']);

        $res = $this->actingAsUser($reporter)->postJson("/api/articles/{$article->slug}/plagiarism-check/")->assertCreated();

        $res->assertJsonStructure(['id', 'provider', 'scan_id', 'status', 'error_message', 'similarity_score', 'matches', 'requested_by_email', 'created_at', 'completed_at'])
            ->assertJson(['provider' => 'COPYLEAKS', 'status' => 'PENDING', 'similarity_score' => null, 'matches' => [], 'completed_at' => null,
                'requested_by_email' => $reporter->email]);
        $scan = $res->json('scan_id');
        $this->assertSame(32, strlen($scan));

        Http::assertSent(function (HttpRequest $r) use ($scan) {
            if (! str_starts_with($r->url(), 'https://api.copyleaks.com/v3/scans/submit/file/')) {
                return false;
            }
            $d = $r->data();

            return $r->method() === 'PUT' && str_ends_with($r->url(), $scan)
                && $r->hasHeader('Authorization', 'Bearer tok-abc')
                && base64_decode($d['base64']) === 'Hello & world of news'
                && $d['properties']['sandbox'] === true
                && $d['properties']['webhooks']['status'] === "https://api.example.com/api/ai/plagiarism-webhook/wh-secret-123/{$scan}/{STATUS}";
        });

        $this->app['auth']->forgetGuards();
        $rows = $this->actingAsUser($reporter)->getJson("/api/articles/{$article->slug}/plagiarism-check/")->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame($scan, $rows[0]['scan_id']);
    }

    public function test_token_is_cached_between_submissions(): void
    {
        $this->fakeCopyleaks();
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->create();

        $this->actingAsUser($admin);
        $this->postJson("/api/articles/{$article->slug}/plagiarism-check/")->assertCreated();
        $this->postJson("/api/articles/{$article->slug}/plagiarism-check/")->assertCreated();

        $logins = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'id.copyleaks.com'));
        $this->assertCount(1, $logins);
        $this->assertSame('tok-abc', Cache::get('copyleaks:access_token'));
    }

    public function test_submit_failures_store_failed_row_without_leaking_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->create();
        $mode = 'login401';
        Http::fake(function (HttpRequest $r) use (&$mode) {
            if (str_contains($r->url(), 'id.copyleaks.com')) {
                return $mode === 'login401' ? Http::response(['message' => 'bad key cl-key-secret'], 401) : Http::response(['access_token' => 'tok']);
            }

            return Http::response('boom cl-key-secret', 500);
        });

        $res = $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/plagiarism-check/")->assertCreated();
        $res->assertJson(['status' => 'FAILED', 'error_message' => 'Copyleaks login failed (HTTP 401).']);
        $this->assertStringNotContainsString('cl-key-secret', $res->getContent());

        $mode = 'ok';
        Cache::forget('copyleaks:access_token');
        $res = $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/plagiarism-check/")
            ->assertCreated()->assertJson(['status' => 'FAILED', 'error_message' => 'Copyleaks submit-scan failed (HTTP 500).']);
        $this->assertStringNotContainsString('cl-key-secret', $res->getContent());

        config(['portal.copyleaks.api_key' => '']);
        Cache::forget('copyleaks:access_token');
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/plagiarism-check/")
            ->assertCreated()->assertJson(['status' => 'FAILED', 'error_message' => 'The plagiarism provider is not configured.']);

        config(['portal.copyleaks.webhook_secret' => '', 'portal.copyleaks.api_key' => 'k']);
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/plagiarism-check/")
            ->assertCreated()->assertJson(['status' => 'FAILED', 'error_message' => 'The plagiarism webhook is not configured.']);
    }

    public function test_401_on_submit_relogs_in_once(): void
    {
        Cache::put('copyleaks:access_token', 'stale', 3600);
        Http::fake([
            'id.copyleaks.com/*' => Http::response(['access_token' => 'fresh']),
            'api.copyleaks.com/v3/scans/submit/file/*' => Http::sequence()->push('', 401)->push('', 201),
        ]);
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->create();

        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/plagiarism-check/")->assertCreated()->assertJson(['status' => 'PENDING']);
        $this->assertSame('fresh', Cache::get('copyleaks:access_token'));
    }

    public function test_webhook_completed_stores_result_from_authenticated_fetch_not_from_payload(): void
    {
        $this->fakeCopyleaks();
        $row = $this->pending();

        // The callback body claims a bogus score; it must be ignored in favour of the fetched report.
        $this->hook($row->scan_id, ['status' => 0, 'results' => ['score' => ['aggregatedScore' => 99]]])->assertOk();

        $row->refresh();
        $this->assertSame('COMPLETED', $row->status);
        $this->assertSame(37.5, $row->similarity_score);
        $this->assertNotNull($row->completed_at);
        $this->assertEquals([
            ['source_url' => 'https://src.example/a', 'similarity_percent' => 42.0, 'matched_text' => 'Source A'],
            ['source_url' => 'DB doc', 'similarity_percent' => 12.5, 'matched_text' => 'DB doc'],
        ], $row->matches);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), "/v3.4/downloads/{$row->scan_id}/result") && $r->hasHeader('Authorization', 'Bearer tok-abc'));
    }

    public function test_webhook_never_touches_article_or_workflow(): void
    {
        $this->fakeCopyleaks();
        $article = Article::factory()->status(ArticleStatus::UNDER_REVIEW)->create();
        $before = $article->fresh()->only(['status', 'updated_at']);
        $row = $this->pending($article);

        $this->hook($row->scan_id)->assertOk();

        $this->assertEquals($before, $article->fresh()->only(['status', 'updated_at']));
    }

    public function test_webhook_invalid_secret_is_rejected_and_changes_nothing(): void
    {
        $this->fakeCopyleaks();
        $row = $this->pending();

        $this->hook($row->scan_id, [], 'completed', 'wrong-secret')->assertForbidden();
        $this->hook($row->scan_id, [], 'completed', substr(self::SECRET, 0, -1))->assertForbidden();
        $this->postJson("/api/ai/plagiarism-webhook/{$row->scan_id}/completed")->assertForbidden(); // legacy secret-less URL shape
        $this->postJson("/api/ai/plagiarism-webhook/{$row->scan_id}")->assertNotFound();

        $this->assertSame('PENDING', $row->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_everything_when_secret_not_configured(): void
    {
        config(['portal.copyleaks.webhook_secret' => '']);
        $row = $this->pending();
        $this->hook($row->scan_id, [], 'completed', 'x')->assertForbidden();
        $this->assertSame('PENDING', $row->fresh()->status);
    }

    public function test_webhook_unknown_scan_is_acknowledged_and_ignored(): void
    {
        Http::fake();
        $this->hook(bin2hex(random_bytes(16)))->assertOk();
        Http::assertNothingSent();
        $this->assertSame(0, PlagiarismCheckResult::count());
    }

    public function test_webhook_duplicate_and_finished_scans_are_idempotent(): void
    {
        $this->fakeCopyleaks();
        $row = $this->pending();

        $this->hook($row->scan_id)->assertOk();
        $first = $row->fresh();
        $this->assertSame('COMPLETED', $first->status);

        // Replay, plus a late "error" callback and a different report: nothing may change and no re-fetch happens.
        Http::fake(['api.copyleaks.com/*' => Http::response(['results' => ['score' => ['aggregatedScore' => 1]]])]);
        $this->hook($row->scan_id)->assertOk();
        $this->hook($row->scan_id, ['status' => 1, 'error' => ['message' => 'late']], 'error')->assertOk();

        $again = $row->fresh();
        $this->assertSame('COMPLETED', $again->status);
        $this->assertSame(37.5, $again->similarity_score);
        $this->assertEquals($first->completed_at, $again->completed_at);
        Http::assertNothingSent();
    }

    public function test_webhook_error_status_marks_failed_with_sanitised_message(): void
    {
        Http::fake();
        $row = $this->pending();

        $this->hook($row->scan_id, ['status' => 1, 'error' => ['code' => 4, 'message' => '<b>Unsupported file</b>']], 'error')->assertOk();

        $row->refresh();
        $this->assertSame('FAILED', $row->status);
        $this->assertSame('Unsupported file', $row->error_message);
        $this->assertNull($row->similarity_score);
        Http::assertNothingSent();
    }

    public function test_intermediate_statuses_do_not_finish_the_scan(): void
    {
        Http::fake();
        $row = $this->pending();
        $this->hook($row->scan_id, ['status' => 2], 'creditsChecked')->assertOk();
        $this->hook($row->scan_id, [], 'indexed')->assertOk();
        $this->assertSame('PENDING', $row->fresh()->status);
    }

    public function test_result_fetch_failure_marks_failed(): void
    {
        Http::fake([
            'id.copyleaks.com/*' => Http::response(['access_token' => 'tok']),
            'api.copyleaks.com/*' => Http::response('nope', 500),
        ]);
        $row = $this->pending();
        $this->hook($row->scan_id)->assertOk();
        $row->refresh();
        $this->assertSame('FAILED', $row->status);
        $this->assertSame('Copyleaks result fetch failed (HTTP 500).', $row->error_message);
    }

    public function test_expire_pending_command_only_times_out_old_pending_rows(): void
    {
        $old = $this->pending(attrs: ['created_at' => now()->subHours(25)]);
        $recent = $this->pending(attrs: ['created_at' => now()->subHours(23)]);
        $done = $this->pending(attrs: ['created_at' => now()->subDays(3), 'status' => 'COMPLETED', 'similarity_score' => 5]);

        $this->artisan('plagiarism:expire-pending')->assertSuccessful();

        $this->assertSame('FAILED', $old->fresh()->status);
        $this->assertStringContainsString('Timed out', $old->fresh()->error_message);
        $this->assertNotNull($old->fresh()->completed_at);
        $this->assertSame('PENDING', $recent->fresh()->status);
        $this->assertSame('COMPLETED', $done->fresh()->status);

        // idempotent
        $this->artisan('plagiarism:expire-pending')->assertSuccessful();
        $this->assertSame('PENDING', $recent->fresh()->status);

        // a webhook arriving after the timeout is treated as duplicate/finished
        Http::fake();
        $this->hook($old->scan_id)->assertOk();
        $this->assertSame('FAILED', $old->fresh()->status);
    }

    public function test_expire_pending_is_scheduled_and_respects_config(): void
    {
        config(['portal.copyleaks.pending_timeout_hours' => 1]);
        $row = $this->pending(attrs: ['created_at' => now()->subHours(2)]);
        $this->artisan('plagiarism:expire-pending')->assertSuccessful();
        $this->assertSame('FAILED', $row->fresh()->status);

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.'|'.$e->description);
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'plagiarism:expire-pending')));
    }

    public function test_permission_matrix_and_advisory_only(): void
    {
        $this->fakeCopyleaks();
        $author = User::factory()->reporter()->create();
        $assigned = User::factory()->reporter()->create();
        $other = User::factory()->reporter()->create();
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->subscriber()->create();
        $draft = Article::factory()->create(['author_id' => $author->id, 'assigned_reporter_id' => $assigned->id]);
        $published = Article::factory()->published()->create(['author_id' => $author->id]);

        $cases = [
            [$author, $draft, 200, 201], [$assigned, $draft, 200, 201], [$admin, $draft, 200, 201],
            [$other, $draft, 404, 404], [$other, $published, 403, 403],
            [$subscriber, $draft, 404, 403], [$subscriber, $published, 403, 403],
        ];
        foreach ($cases as [$user, $article, $get, $post]) {
            $this->app['auth']->forgetGuards();
            $this->actingAsUser($user)->getJson("/api/articles/{$article->slug}/plagiarism-check/")->assertStatus($get);
            $this->app['auth']->forgetGuards();
            $this->actingAsUser($user)->postJson("/api/articles/{$article->slug}/plagiarism-check/")->assertStatus($post);
        }
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/articles/{$draft->slug}/plagiarism-check/")->assertUnauthorized();
        $this->assertSame(ArticleStatus::DRAFT, $draft->fresh()->status);
    }

    public function test_admin_site_wide_list(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Article::factory()->status(ArticleStatus::SUBMITTED)->create();
        $r1 = $this->pending($a);
        $r2 = $this->pending(attrs: ['status' => 'FAILED', 'error_message' => 'x']);

        $this->actingAsUser($admin);
        $this->getJson('/api/ai/plagiarism-results/')->assertOk()->assertJsonPath('count', 2)->assertJsonPath('results.0.id', $r2->id)
            ->assertJsonStructure(['results' => [['id', 'scan_id', 'article_id', 'article_title', 'article_slug', 'article_status', 'article_author_email', 'article_assigned_reporter_email']]]);
        $this->getJson('/api/ai/plagiarism-results/?status=PENDING&article_status=SUBMITTED')->assertJsonPath('count', 1)->assertJsonPath('results.0.id', $r1->id);
        $this->getJson("/api/ai/plagiarism-results/{$r1->id}/")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAsUser(User::factory()->reporter()->create())->getJson('/api/ai/plagiarism-results/')->assertForbidden();
    }
}
