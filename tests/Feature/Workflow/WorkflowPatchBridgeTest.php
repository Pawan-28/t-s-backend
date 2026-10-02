<?php

namespace Tests\Feature\Workflow;

use App\Enums\ArticleStatus as S;
use App\Models\ArticleReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/**
 * The generic admin article PATCH (owned by the articles area) must reach articles.status only through
 * ArticleWorkflowService. Skipped automatically when that endpoint is not present in this checkout.
 */
class WorkflowPatchBridgeTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $exists = collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'api/articles/{slug}' && in_array('PATCH', $r->methods(), true));
        if (! $exists) {
            $this->markTestSkipped('PATCH /api/articles/{slug} not available.');
        }
        $this->setUpWorkflow();
        Queue::fake();
    }

    private function patch_(?User $u, string $slug, array $body)
    {
        $this->app['auth']->forgetGuards();
        if ($u) {
            $this->actingAsUser($u);
        }

        return $this->patchJson("/api/articles/{$slug}/", $body);
    }

    public function test_admin_patch_status_leaves_review_history_like_the_action_endpoints(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->patch_($this->admin, $a->slug, ['status' => 'APPROVED'])->assertOk()->assertJsonPath('status', 'APPROVED');
        $row = ArticleReview::where('article_id', $a->id)->sole();
        $this->assertSame('APPROVED', $row->action->value);
        $this->assertSame($this->admin->id, $row->reviewer_id);
        $this->assertDatabaseHas('notifications', ['recipient_id' => $this->author->id, 'notification_type' => 'ARTICLE_APPROVED']);
    }

    public function test_admin_patch_cannot_skip_the_map(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->patch_($this->admin, $a->slug, ['status' => 'PUBLISHED'])->assertStatus(400);
        $this->patch_($this->admin, $a->slug, ['status' => 'DRAFT'])->assertStatus(400);
        $this->assertSame(S::SUBMITTED, $this->statusOf($a));
        $this->assertSame(0, ArticleReview::count());
    }

    public function test_reporter_cannot_reach_review_states_by_patch(): void
    {
        $a = $this->article(S::DRAFT);
        foreach (['APPROVED', 'PUBLISHED', 'UNDER_REVIEW'] as $to) {
            $this->assertContains($this->patch_($this->author, $a->slug, ['status' => $to])->status(), [400, 403]);
        }
        $this->assertSame(S::DRAFT, $this->statusOf($a));
        $this->assertSame($this->author->id, $a->fresh()->author_id);
    }
}
