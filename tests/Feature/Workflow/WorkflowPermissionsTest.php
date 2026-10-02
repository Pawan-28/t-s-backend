<?php

namespace Tests\Feature\Workflow;

use App\Enums\ArticleStatus as S;
use App\Enums\Role;
use App\Models\ArticleReview;
use App\Models\PublishingSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/** Role / ownership matrix: guest, user, other reporter, author, assigned reporter, admin. */
class WorkflowPermissionsTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
        Queue::fake();
    }

    /** @return array<string, array{0:string,1:S,2:array,3:array<string,int>}> */
    public static function matrix(): array
    {
        // action, article status (assigned reporter always set), body, expected HTTP code per actor
        $body = fn (string $a) => match ($a) {
            'reject', 'request-changes' => ['reason' => 'because'],
            'schedule' => ['scheduled_for' => '+1 day'],
            'assign-reporter' => ['reporter_id' => 'ASSIGNEE'],
            default => [],
        };

        return [
            'submit' => ['submit', S::DRAFT, [], ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 200, 'assignee' => 200, 'admin' => 403]],
            'start-review' => ['start-review', S::SUBMITTED, [], ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 403, 'admin' => 200]],
            'request-changes' => ['request-changes', S::SUBMITTED, $body('request-changes'), ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 403, 'admin' => 200]],
            'reject' => ['reject', S::UNDER_REVIEW, $body('reject'), ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 200, 'admin' => 200]],
            'approve' => ['approve', S::UNDER_REVIEW, [], ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 200, 'admin' => 200]],
            'publish' => ['publish', S::APPROVED, [], ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 200, 'admin' => 200]],
            'schedule' => ['schedule', S::APPROVED, $body('schedule'), ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 200, 'admin' => 200]],
            'cancel-schedule' => ['cancel-schedule', S::SCHEDULED, [], ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 403, 'admin' => 200]],
            'assign-reporter' => ['assign-reporter', S::SUBMITTED, $body('assign-reporter'), ['guest' => 401, 'user' => 403, 'other' => 404, 'author' => 403, 'assignee' => 404, 'admin' => 200]],
        ];
    }

    #[DataProvider('matrix')]
    public function test_role_matrix(string $action, S $status, array $body, array $expect): void
    {
        foreach ($expect as $who => $code) {
            // fresh article per actor so state never leaks between rows; assignee assigned except for the assign action
            $a = $this->article($status, $action === 'assign-reporter' ? [] : ['assigned_reporter_id' => $this->assignee->id]);
            $actor = match ($who) {
                'guest' => null, 'user' => $this->plainUser, 'other' => $this->otherReporter,
                'author' => $this->author, 'assignee' => $this->assignee, 'admin' => $this->admin,
            };
            $payload = $body;
            if (($payload['scheduled_for'] ?? null) === '+1 day') {
                $payload['scheduled_for'] = now()->addDay()->toIso8601String();
            }
            if (($payload['reporter_id'] ?? null) === 'ASSIGNEE') {
                $payload['reporter_id'] = $this->assignee->id;
            }
            $r = $this->act($actor, $a->slug, $action, $payload);
            $r->assertStatus($code);
            if ($code >= 400) {
                $r->assertJsonStructure(['detail']);
                $this->assertSame($status, $this->statusOf($a), "$who $action must not change state");
                $this->assertSame(0, ArticleReview::where('article_id', $a->id)->count());
            } else {
                $this->assertNotSame($status, $this->statusOf($a));
            }
        }
    }

    public function test_other_reporter_sees_published_article_but_is_403_not_404(): void
    {
        $a = $this->article(S::PUBLISHED, ['published_at' => now()->subDay()]);
        $this->act($this->otherReporter, $a->slug, 'approve')->assertStatus(403)->assertJsonPath('detail', 'You do not have permission to perform this action.');
    }

    public function test_plain_user_gets_the_writer_role_message(): void
    {
        $a = $this->article(S::PUBLISHED, ['published_at' => now()]);
        $this->act($this->plainUser, $a->slug, 'submit')->assertStatus(403)
            ->assertJsonPath('detail', 'Only reporters and administrators can create or modify articles.');
    }

    public function test_403_messages_match_django(): void
    {
        $a = $this->article(S::UNDER_REVIEW, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->author, $a->slug, 'start-review')->assertJsonPath('detail', 'Only administrators can start a review.');
        $this->act($this->author, $a->slug, 'request-changes', ['reason' => 'x'])->assertJsonPath('detail', 'Only administrators can request changes.');
        $this->act($this->author, $a->slug, 'reject', ['reason' => 'x'])->assertJsonPath('detail', "Only administrators or this article's assigned reporter can reject it.");
        $this->act($this->author, $a->slug, 'approve')->assertJsonPath('detail', "Only administrators or this article's assigned reporter can approve it.");
        $this->act($this->author, $a->slug, 'publish')->assertJsonPath('detail', "Only administrators or this article's assigned reporter can publish it.");
        $this->act($this->assignee, $a->slug, 'cancel-schedule')->assertJsonPath('detail', 'Only administrators can cancel a schedule.');
        $this->act($this->assignee, $a->slug, 'assign-reporter', ['reporter_id' => 1])->assertJsonPath('detail', 'Only administrators can assign a reporter.');
        $d = $this->article();
        $this->act($this->admin, $d->slug, 'submit')->assertJsonPath('detail', "Only the article's author or assigned reporter may submit it for review.");
    }

    public function test_permission_is_checked_before_body_validation(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->act($this->author, $a->slug, 'reject', [])->assertStatus(403);
        $this->act($this->author, $a->slug, 'schedule', [])->assertStatus(403);
    }

    public function test_author_cannot_review_own_article_even_if_also_assigned(): void
    {
        // legacy / bad data: author == assigned reporter -> still no self-review
        $a = $this->article(S::UNDER_REVIEW, ['assigned_reporter_id' => $this->author->id]);
        $this->act($this->author, $a->slug, 'approve')->assertStatus(403);
        $this->act($this->author, $a->slug, 'publish')->assertStatus(403);
        $this->act($this->admin, $a->slug, 'approve')->assertOk();
    }

    public function test_admin_who_authored_the_article_may_still_act(): void
    {
        $a = $this->article(S::SUBMITTED, ['author_id' => $this->admin->id]);
        $this->act($this->admin, $a->slug, 'approve')->assertOk();
    }

    public function test_demoted_assignee_loses_review_rights(): void
    {
        $a = $this->article(S::UNDER_REVIEW, ['assigned_reporter_id' => $this->assignee->id]);
        $this->assignee->forceFill(['role' => Role::USER])->save();
        $this->act($this->assignee->fresh(), $a->slug, 'approve')->assertStatus(403);
    }

    public function test_inactive_user_is_rejected_at_the_door(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->admin->forceFill(['is_active' => false])->save();
        $this->act($this->admin->fresh(), $a->slug, 'approve')->assertStatus(401);
    }

    public function test_unknown_article_is_404_for_writers(): void
    {
        $this->act($this->admin, 'no-such-article', 'approve')->assertStatus(404)->assertJsonPath('detail', 'Not found.');
        $this->act($this->author, 'no-such-article', 'submit')->assertStatus(404);
    }

    public function test_urls_work_with_and_without_trailing_slash_and_wrong_method_is_405(): void
    {
        $a = $this->article();
        $this->app['auth']->forgetGuards();
        $this->actingAsUser($this->author);
        $this->postJson("/api/articles/{$a->slug}/submit")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAsUser($this->admin);
        $this->getJson("/api/articles/{$a->slug}/approve/")->assertStatus(405);
    }

    // ------------------------------------------------------------------ review history

    public function test_review_history_access_and_shape_newest_first(): void
    {
        $a = $this->article();
        $this->act($this->author, $a->slug, 'submit')->assertOk();
        $this->act($this->admin, $a->slug, 'request-changes', ['reason' => 'Fix'])->assertOk();
        ArticleReview::where('article_id', $a->id)->where('action', 'SUBMITTED')->update(['created_at' => now()->subHour()]);

        $r = $this->getAs($this->author, "/api/articles/{$a->slug}/review-history/")->assertOk();
        $rows = $r->json();
        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'action', 'from_status', 'to_status', 'reason', 'reviewer_email', 'created_at'], array_keys($rows[0]));
        $this->assertSame('CHANGES_REQUESTED', $rows[0]['action']);
        $this->assertSame('Fix', $rows[0]['reason']);
        $this->assertSame($this->admin->email, $rows[0]['reviewer_email']);
        $this->assertSame('SUBMITTED', $rows[1]['action']);

        $a->forceFill(['assigned_reporter_id' => $this->assignee->id])->save();
        $this->getAs($this->assignee, "/api/articles/{$a->slug}/review-history")->assertOk()->assertJsonCount(2);
        $this->getAs($this->admin, "/api/articles/{$a->slug}/review-history/")->assertOk()->assertJsonCount(2);
    }

    public function test_review_history_is_not_public_and_not_for_strangers(): void
    {
        $a = $this->article(S::PUBLISHED, ['published_at' => now()->subDay()]);
        ArticleReview::create(['article_id' => $a->id, 'reviewer_id' => $this->admin->id, 'action' => 'APPROVED', 'from_status' => 'SUBMITTED', 'to_status' => 'APPROVED']);
        $this->getAs(null, "/api/articles/{$a->slug}/review-history/")->assertStatus(401);
        $this->getAs($this->plainUser, "/api/articles/{$a->slug}/review-history/")->assertStatus(403);
        $this->getAs($this->otherReporter, "/api/articles/{$a->slug}/review-history/")->assertStatus(403);
        $draft = $this->article();
        $this->getAs($this->otherReporter, "/api/articles/{$draft->slug}/review-history/")->assertStatus(404);
    }

    public function test_review_history_shows_null_reviewer_for_automatic_publish(): void
    {
        $a = $this->article(S::SCHEDULED, ['scheduled_publish_at' => now()->subMinute()]);
        PublishingSchedule::create(['article_id' => $a->id, 'scheduled_for' => now()->subMinute(), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);
        $this->artisan('articles:publish-due')->assertSuccessful();
        $rows = $this->getAs($this->admin, "/api/articles/{$a->slug}/review-history/")->assertOk()->json();
        $this->assertSame('PUBLISHED', $rows[0]['action']);
        $this->assertNull($rows[0]['reviewer_email']);
    }
}
