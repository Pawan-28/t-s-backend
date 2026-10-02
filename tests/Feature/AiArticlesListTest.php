<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/ai/articles/ : every article with its latest AI analysis (admin AI screen). */
class AiArticlesListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function analysis(Article $a, string $status, array $extra = []): AiAnalysisResult
    {
        return AiAnalysisResult::create($extra + ['article_id' => $a->id, 'requested_by_id' => $this->admin->id, 'provider' => 'GEMINI',
            'model_name' => 'm', 'status' => $status, 'readability_score' => 55, 'ai_content_likelihood' => 0.3]);
    }

    public function test_lists_every_article_with_latest_analysis_or_null(): void
    {
        $never = Article::factory()->status(ArticleStatus::DRAFT)->create(['title' => 'Never analysed']);
        $twice = Article::factory()->status(ArticleStatus::PUBLISHED)->create(['title' => 'Analysed twice']);
        $this->analysis($twice, 'FAILED');
        $this->analysis($twice, 'COMPLETED', ['readability_score' => 81]);

        $r = $this->actingAsUser($this->admin)->getJson('/api/ai/articles/')->assertOk();
        $this->assertSame(2, $r->json('count'));
        $rows = collect($r->json('results'))->keyBy('slug');
        $this->assertNull($rows[$never->slug]['latest_analysis']);
        $this->assertSame(0, $rows[$never->slug]['analysis_count']);
        $this->assertSame(2, $rows[$twice->slug]['analysis_count']);
        $this->assertSame('COMPLETED', $rows[$twice->slug]['latest_analysis']['status']);
        $this->assertEquals(81, $rows[$twice->slug]['latest_analysis']['readability_score']);
        $r->assertJsonStructure(['results' => [['id', 'title', 'slug', 'status', 'author_email', 'assigned_reporter_email', 'updated_at', 'analysis_count', 'latest_analysis']]]);
    }

    public function test_filters(): void
    {
        $a = Article::factory()->status(ArticleStatus::PUBLISHED)->create(['title' => 'Alpha done']);
        $b = Article::factory()->status(ArticleStatus::DRAFT)->create(['title' => 'Beta failed']);
        $c = Article::factory()->status(ArticleStatus::DRAFT)->create(['title' => 'Gamma none']);
        $this->analysis($a, 'COMPLETED');
        $this->analysis($b, 'COMPLETED');
        $this->analysis($b, 'FAILED'); // latest is FAILED
        $get = fn (string $qs) => $this->actingAsUser($this->admin)->getJson('/api/ai/articles/?'.$qs);

        $this->assertSame([$c->slug], array_column($get('ai=none')->assertOk()->json('results'), 'slug'));
        $this->assertSame([$a->slug], array_column($get('ai=completed')->assertOk()->json('results'), 'slug'));
        $this->assertSame([$b->slug], array_column($get('ai=failed')->assertOk()->json('results'), 'slug'));
        $this->assertSame(3, $get('ai=any')->json('count'));
        $this->assertSame([$a->slug], array_column($get('article_status=PUBLISHED')->json('results'), 'slug'));
        $this->assertSame([$b->slug], array_column($get('search=beta')->json('results'), 'slug'));
        $this->assertSame(0, $get('search='.urlencode('%'))->json('count'));
        $get('ai=bogus')->assertStatus(400)->assertJsonStructure(['ai']);
        $get('article_status=NOPE')->assertStatus(400)->assertJsonStructure(['article_status']);
    }

    public function test_requires_the_analytics_permission(): void
    {
        $reporter = User::factory()->reporter()->create();
        $this->actingAsUser($reporter)->getJson('/api/ai/articles/')->assertForbidden();
        $reporter->permissions = ['analytics.view'];
        $reporter->save();
        $this->actingAsUser($reporter->fresh())->getJson('/api/ai/articles/')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/ai/articles/')->assertUnauthorized();
    }
}
