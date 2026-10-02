<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI analysis through Google Gemini (AI_PROVIDER=gemini) and provider selection. */
class GeminiAiCheckTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD = [
        'readability_score' => 64,
        'grammar_issues' => [['issue' => 'Run-on sentence', 'suggestion' => 'Split it']],
        'seo_suggestions' => ['Shorten the title'],
        'ai_content_likelihood' => 0.1,
        'ai_content_rationale' => 'Natural reporting voice.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.ai.provider' => 'gemini',
            'portal.gemini.api_key' => 'gem-test-secret',
            'portal.gemini.model' => 'gemini-test',
            'portal.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'portal.openai.api_key' => '',
        ]);
    }

    private function geminiOk(array|string $content = self::GOOD): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => is_array($content) ? json_encode($content) : $content]]]]],
        ])]);
    }

    private function adminAndArticle(): array
    {
        $admin = User::factory()->admin()->create();

        return [$admin, Article::factory()->status(ArticleStatus::DRAFT)->create(['author_id' => $admin->id])];
    }

    public function test_provider_selection(): void
    {
        $this->assertInstanceOf(GeminiClient::class, app(AiProvider::class));
        config(['portal.ai.provider' => 'openai']);
        $this->assertInstanceOf(OpenAiClient::class, app(AiProvider::class));
        config(['portal.ai.provider' => '', 'portal.gemini.api_key' => 'k']);
        $this->assertInstanceOf(GeminiClient::class, app(AiProvider::class));
        config(['portal.ai.provider' => '', 'portal.gemini.api_key' => '']);
        $this->assertInstanceOf(OpenAiClient::class, app(AiProvider::class));
    }

    public function test_post_uses_gemini_and_stores_a_completed_result(): void
    {
        $this->geminiOk();
        [$admin, $article] = $this->adminAndArticle();

        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()
            ->assertJson(['provider' => 'GEMINI', 'model_name' => 'gemini-test', 'status' => 'COMPLETED', 'readability_score' => 64.0,
                'grammar_issues' => [['issue' => 'Run-on sentence', 'suggestion' => 'Split it']], 'seo_suggestions' => ['Shorten the title']]);
        $this->assertDatabaseHas('ai_analysis_results', ['article_id' => $article->id, 'provider' => 'GEMINI', 'status' => 'COMPLETED']);

        Http::assertSent(function (HttpRequest $r) {
            $d = $r->data();

            return $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
                && $r->hasHeader('x-goog-api-key', 'gem-test-secret')
                && ! str_contains($r->url(), 'gem-test-secret')
                && ($d['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && str_contains($d['systemInstruction']['parts'][0]['text'] ?? '', 'editorial assistant')
                && str_contains($d['contents'][0]['parts'][0]['text'] ?? '', '<article_body>');
        });
    }

    public function test_article_status_is_never_touched(): void
    {
        $this->geminiOk();
        [$admin, $article] = $this->adminAndArticle();
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated();
        $this->assertSame(ArticleStatus::DRAFT, $article->fresh()->status);
    }

    public function test_markdown_fenced_json_is_accepted(): void
    {
        $this->geminiOk("```json\n".json_encode(self::GOOD)."\n```");
        [$admin, $article] = $this->adminAndArticle();
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()->assertJsonPath('status', 'COMPLETED');
    }

    public function test_thought_parts_are_ignored(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => 'thinking...', 'thought' => true], ['text' => json_encode(self::GOOD)],
        ]]]]])]);
        [$admin, $article] = $this->adminAndArticle();
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()->assertJsonPath('status', 'COMPLETED');
    }

    public function test_provider_errors_become_failed_rows_without_leaking_the_key(): void
    {
        [$admin, $article] = $this->adminAndArticle();
        $cases = [
            [400, 'rejected the request or the API key'], [403, 'rejected the credentials'], [404, 'GEMINI_MODEL'],
            [429, 'rate limit or quota'], [503, 'server error'],
        ];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'raw body gem-test-secret']], 400)
            ->push(['error' => ['message' => 'raw body gem-test-secret']], 403)
            ->push(['error' => ['message' => 'raw body gem-test-secret']], 404)
            ->push(['error' => ['message' => 'raw body gem-test-secret']], 429)
            ->push(['error' => ['message' => 'raw body gem-test-secret']], 503)]);
        foreach ($cases as [$status, $needle]) {
            $r = $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated();
            $r->assertJsonPath('status', 'FAILED')->assertJsonPath('provider', 'GEMINI');
            $this->assertStringContainsString("HTTP {$status}", $r->json('error_message'));
            $this->assertStringContainsString($needle, $r->json('error_message'));
            $this->assertStringNotContainsString('gem-test-secret', $r->getContent());
        }
    }

    public function test_safety_block_and_empty_answers_fail_cleanly(): void
    {
        [$admin, $article] = $this->adminAndArticle();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['promptFeedback' => ['blockReason' => 'SAFETY']])
            ->push(['candidates' => []])
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'not json at all']]]]]])]);

        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()
            ->assertJsonPath('status', 'FAILED')->assertJsonPath('error_message', 'The AI provider blocked this article (safety filter).');
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()->assertJsonPath('status', 'FAILED');
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()->assertJsonPath('status', 'FAILED');
    }

    public function test_missing_key_is_a_failed_row_that_names_the_setting(): void
    {
        config(['portal.gemini.api_key' => '']);
        Http::fake();
        [$admin, $article] = $this->adminAndArticle();
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated()
            ->assertJsonPath('status', 'FAILED')->assertJsonPath('error_message', 'The AI provider is not configured (set GEMINI_API_KEY).');
        Http::assertNothingSent();
    }

    public function test_admin_results_list_accepts_the_gemini_provider_filter(): void
    {
        $this->geminiOk();
        [$admin, $article] = $this->adminAndArticle();
        $this->actingAsUser($admin)->postJson("/api/articles/{$article->slug}/ai-check/")->assertCreated();
        $this->actingAsUser($admin)->getJson('/api/ai/analysis-results/?provider=GEMINI')->assertOk()->assertJsonPath('count', 1);
        $this->actingAsUser($admin)->getJson('/api/ai/analysis-results/?provider=OPENAI')->assertOk()->assertJsonPath('count', 0);
    }
}
