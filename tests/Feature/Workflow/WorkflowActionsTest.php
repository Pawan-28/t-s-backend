<?php

namespace Tests\Feature\Workflow;

use App\Enums\ArticleStatus as S;
use App\Enums\NotificationType;
use App\Enums\ReviewAction;
use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\Notification;
use App\Models\PublishingSchedule;
use App\Models\ReporterCategoryAssignment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Workflow\ArticleWorkflowService;
use App\Services\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

class WorkflowActionsTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
        Queue::fake(); // subscriber fan-out is covered in its own test
    }

    private function lastReview(Article $a): ArticleReview
    {
        return ArticleReview::where('article_id', $a->id)->latest('id')->firstOrFail();
    }

    private function notif(NotificationType $t, $recipient): Collection
    {
        return Notification::where('notification_type', $t->value)->where('recipient_id', $recipient->id)->get();
    }

    // ------------------------------------------------------------------ legal transitions over HTTP

    public function test_author_submits_draft_then_resubmits_after_changes(): void
    {
        $a = $this->article();
        $this->act($this->author, $a->slug, 'submit')->assertOk()
            ->assertJsonPath('status', 'SUBMITTED')->assertJsonPath('slug', $a->slug)->assertJsonPath('author.id', $this->author->id);
        $this->assertSame(ReviewAction::SUBMITTED, $this->lastReview($a)->action);

        $this->act($this->admin, $a->slug, 'request-changes', ['reason' => 'Fix the intro'])->assertOk()->assertJsonPath('status', 'CHANGES_REQUESTED');
        $this->act($this->author, $a->slug, 'submit')->assertOk()->assertJsonPath('status', 'SUBMITTED');
        $r = $this->lastReview($a);
        $this->assertSame(ReviewAction::RESUBMITTED, $r->action);
        $this->assertSame('CHANGES_REQUESTED', $r->from_status);
        $this->assertSame('SUBMITTED', $r->to_status);
    }

    public function test_start_review_from_submitted_records_review_without_notification(): void
    {
        $a = $this->article(S::SUBMITTED);
        $before = Notification::count();
        $this->act($this->admin, $a->slug, 'start-review')->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
        $r = $this->lastReview($a);
        $this->assertSame(ReviewAction::STARTED_REVIEW, $r->action);
        $this->assertSame($this->admin->id, $r->reviewer_id);
        $this->assertSame($before, Notification::count());
    }

    public function test_start_review_on_unassigned_draft_is_refused_but_ok_when_assigned(): void
    {
        $a = $this->article();
        $this->act($this->admin, $a->slug, 'start-review')->assertStatus(400)->assertJsonStructure(['assigned_reporter']);
        $this->assertSame(S::DRAFT, $this->statusOf($a));

        $a->forceFill(['assigned_reporter_id' => $this->assignee->id])->save();
        $this->act($this->admin, $a->slug, 'start-review')->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
    }

    public function test_start_review_on_under_review_is_the_allowed_self_loop(): void
    {
        $a = $this->article(S::UNDER_REVIEW, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->admin, $a->slug, 'start-review')->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
    }

    #[DataProvider('reasonSources')]
    public function test_request_changes_and_reject_from_all_legal_sources(S $from): void
    {
        $a = $this->article($from, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->admin, $a->slug, 'request-changes', ['reason' => '  needs work  '])->assertOk()->assertJsonPath('status', 'CHANGES_REQUESTED');
        $this->assertSame('needs work', $this->lastReview($a)->reason);

        $b = $this->article($from, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->admin, $b->slug, 'reject', ['reason' => 'not fit'])->assertOk()
            ->assertJsonPath('status', 'REJECTED')->assertJsonPath('rejection_reason', 'not fit');
        $this->assertSame(ReviewAction::REJECTED, $this->lastReview($b)->action);
    }

    public static function reasonSources(): array
    {
        return ['draft' => [S::DRAFT], 'submitted' => [S::SUBMITTED], 'under review' => [S::UNDER_REVIEW]];
    }

    #[DataProvider('approveSources')]
    public function test_approve_from_legal_sources(S $from): void
    {
        $a = $this->article($from, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->admin, $a->slug, 'approve')->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertSame(ReviewAction::APPROVED, $this->lastReview($a)->action);
    }

    public static function approveSources(): array
    {
        return ['submitted' => [S::SUBMITTED], 'under review' => [S::UNDER_REVIEW]];
    }

    #[DataProvider('publishSources')]
    public function test_publish_now_from_legal_sources_sets_published_at_and_clears_schedule(S $from): void
    {
        $a = $this->article($from, ['scheduled_publish_at' => $from === S::SCHEDULED ? now()->addDay() : null]);
        if ($from === S::SCHEDULED) {
            PublishingSchedule::create(['article_id' => $a->id, 'scheduled_for' => now()->addDay(), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);
        }
        $this->act($this->admin, $a->slug, 'publish')->assertOk()
            ->assertJsonPath('status', 'PUBLISHED')->assertJsonPath('scheduled_publish_at', null);
        $fresh = $a->fresh();
        $this->assertNotNull($fresh->published_at);
        $this->assertNull($fresh->scheduled_publish_at);
        $this->assertSame(0, PublishingSchedule::where('article_id', $a->id)->where('status', 'PENDING')->count());
        $this->assertSame(ReviewAction::PUBLISHED, $this->lastReview($a)->action);
    }

    public static function publishSources(): array
    {
        return ['draft' => [S::DRAFT], 'under review' => [S::UNDER_REVIEW], 'approved' => [S::APPROVED], 'scheduled' => [S::SCHEDULED]];
    }

    #[DataProvider('publishSources')]
    public function test_schedule_from_legal_sources_in_the_future(S $from): void
    {
        $a = $this->article($from);
        $at = now()->addHours(3)->startOfSecond();
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => $at->toIso8601String()])->assertOk()->assertJsonPath('status', 'SCHEDULED');
        $fresh = $a->fresh();
        $sched = PublishingSchedule::where('article_id', $a->id)->where('status', 'PENDING')->get();
        $this->assertCount(1, $sched);
        $this->assertTrue($fresh->scheduled_publish_at->equalTo($sched[0]->scheduled_for));
        $this->assertTrue($fresh->scheduled_publish_at->greaterThan(now()));
        $this->assertSame($from === S::SCHEDULED ? ReviewAction::RESCHEDULED : ReviewAction::SCHEDULED, $this->lastReview($a)->action);
    }

    public function test_cancel_schedule_returns_to_approved_and_cancels_pending_row(): void
    {
        $a = $this->article(S::APPROVED);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->addDay()->toIso8601String()])->assertOk();
        $this->act($this->admin, $a->slug, 'cancel-schedule')->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('scheduled_publish_at', null);
        $this->assertSame(ReviewAction::SCHEDULE_CANCELLED, $this->lastReview($a)->action);
        $this->assertSame(['CANCELLED'], PublishingSchedule::where('article_id', $a->id)->pluck('status')->all());
    }

    // ------------------------------------------------------------------ illegal transitions over HTTP

    /** every (action, status) pair outside the map answers 400 {"status": "Cannot change status ..."}. */
    #[DataProvider('illegalPairs')]
    public function test_illegal_transition_is_400_and_changes_nothing(string $action, S $from): void
    {
        $a = $this->article($from, ['assigned_reporter_id' => $this->assignee->id]);
        $actor = $action === 'submit' ? $this->author : $this->admin;
        $body = match ($action) {
            'request-changes', 'reject' => ['reason' => 'x'],
            'schedule' => ['scheduled_for' => now()->addDay()->toIso8601String()],
            'assign-reporter' => ['reporter_id' => $this->assignee->id],
            default => [],
        };
        $r = $this->act($actor, $a->slug, $action, $body)->assertStatus(400);
        $this->assertArrayHasKey('status', $r->json());
        $this->assertStringContainsString('Cannot', $r->json('status'));
        $this->assertSame($from, $this->statusOf($a));
        $this->assertSame(0, ArticleReview::where('article_id', $a->id)->count());
    }

    public static function illegalPairs(): array
    {
        $target = ['submit' => S::SUBMITTED, 'start-review' => S::UNDER_REVIEW, 'request-changes' => S::CHANGES_REQUESTED,
            'reject' => S::REJECTED, 'approve' => S::APPROVED, 'publish' => S::PUBLISHED, 'schedule' => S::SCHEDULED, 'cancel-schedule' => S::APPROVED];
        $out = [];
        foreach ($target as $action => $to) {
            foreach (S::cases() as $from) {
                // start-review from DRAFT has its own message (covered elsewhere); cancel-schedule is legal only from SCHEDULED.
                if ($action === 'start-review' && $from === S::DRAFT) {
                    continue;
                }
                if ($action === 'cancel-schedule' ? $from !== S::SCHEDULED : ! $from->canTransitionTo($to)) {
                    $out["$action from {$from->value}"] = [$action, $from];
                }
            }
        }

        return $out;
    }

    public function test_assign_reporter_from_illegal_statuses_is_400(): void
    {
        foreach ([S::CHANGES_REQUESTED, S::REJECTED, S::APPROVED, S::SCHEDULED, S::PUBLISHED] as $from) {
            $a = $this->article($from);
            $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])
                ->assertStatus(400)->assertJsonStructure(['status']);
        }
    }

    public function test_double_action_is_not_idempotent_second_call_400(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->act($this->admin, $a->slug, 'approve')->assertOk();
        $this->act($this->admin, $a->slug, 'approve')->assertStatus(400);
        $this->assertSame(1, ArticleReview::where('article_id', $a->id)->count());
    }

    public function test_terminal_states_admit_nothing(): void
    {
        foreach ([S::REJECTED, S::PUBLISHED] as $from) {
            $a = $this->article($from);
            foreach (['start-review', 'approve', 'publish'] as $action) {
                $this->act($this->admin, $a->slug, $action)->assertStatus(400);
            }
            $this->act($this->admin, $a->slug, 'reject', ['reason' => 'x'])->assertStatus(400);
        }
    }

    // ------------------------------------------------------------------ input validation (DRF-shaped bodies)

    public function test_reason_is_required_not_blank_and_capped(): void
    {
        $a = $this->article(S::SUBMITTED);
        foreach (['reject', 'request-changes'] as $action) {
            $this->act($this->admin, $a->slug, $action)->assertStatus(400)->assertExactJson(['reason' => ['This field is required.']]);
            $this->act($this->admin, $a->slug, $action, ['reason' => '   '])->assertStatus(400)->assertExactJson(['reason' => ['This field may not be blank.']]);
            $this->act($this->admin, $a->slug, $action, ['reason' => null])->assertStatus(400)->assertExactJson(['reason' => ['This field may not be null.']]);
            $this->act($this->admin, $a->slug, $action, ['reason' => str_repeat('x', 2001)])->assertStatus(400)
                ->assertExactJson(['reason' => ['Ensure this field has no more than 2000 characters.']]);
        }
        $this->assertSame(S::SUBMITTED, $this->statusOf($a));
    }

    public function test_schedule_requires_valid_future_datetime(): void
    {
        $a = $this->article(S::APPROVED);
        $this->act($this->admin, $a->slug, 'schedule')->assertStatus(400)->assertExactJson(['scheduled_for' => ['This field is required.']]);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => 'tomorrow'])->assertStatus(400)->assertJsonStructure(['scheduled_for']);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->subMinute()->toIso8601String()])->assertStatus(400)
            ->assertExactJson(['scheduled_for' => ['scheduled_for must be a future date/time.']]);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->toIso8601String()])->assertStatus(400);
        $this->assertSame(S::APPROVED, $this->statusOf($a));
        $this->assertSame(0, PublishingSchedule::count());
    }

    public function test_schedule_accepts_z_suffix_and_naive_project_timezone(): void
    {
        $a = $this->article(S::APPROVED);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z')])->assertOk();
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now(config('app.timezone'))->addDays(2)->format('Y-m-d\TH:i:s')])->assertOk();
    }

    public function test_assign_reporter_input_errors(): void
    {
        $a = $this->article();
        $this->act($this->admin, $a->slug, 'assign-reporter')->assertStatus(400)->assertExactJson(['reporter_id' => ['This field is required.']]);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => 'abc'])->assertStatus(400)->assertExactJson(['reporter_id' => ['A valid integer is required.']]);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => 999999])->assertStatus(400)->assertExactJson(['reporter_id' => 'No user with this id exists.']);
    }

    // ------------------------------------------------------------------ assignment

    public function test_assign_reporter_on_a_draft_keeps_it_draft_notifies_and_keeps_author(): void
    {
        $a = $this->article();
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertOk()
            ->assertJsonPath('status', 'DRAFT')->assertJsonPath('assigned_reporter.id', $this->assignee->id)
            ->assertJsonPath('author.id', $this->author->id);
        $r = $this->lastReview($a);
        $this->assertSame(ReviewAction::ASSIGNED, $r->action);
        $this->assertSame('DRAFT', $r->from_status);
        $this->assertSame('DRAFT', $r->to_status);
        $this->assertSame("Assigned to reporter {$this->assignee->email}.", $r->reason);
        $n = $this->notif(NotificationType::ARTICLE_ASSIGNED_FOR_REVIEW, $this->assignee);
        $this->assertCount(1, $n);
        $this->assertSame("You were assigned to review/edit \"{$a->title}\".", $n[0]->message);
    }

    public function test_after_assigning_a_draft_the_admin_can_move_it_to_under_review(): void
    {
        $a = $this->article();
        $this->act($this->admin, $a->slug, 'start-review')->assertStatus(400)->assertJsonStructure(['assigned_reporter']); // no reporter yet
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertOk()->assertJsonPath('status', 'DRAFT');
        $this->act($this->admin, $a->slug, 'start-review')->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
        $this->assertSame(ReviewAction::STARTED_REVIEW, $this->lastReview($a)->action);
    }

    public function test_after_assigning_a_draft_it_can_still_be_published_scheduled_or_rejected(): void
    {
        foreach (['publish' => [], 'reject' => ['reason' => 'No.'], 'schedule' => ['scheduled_for' => now()->addDay()->toIso8601String()]] as $action => $body) {
            $a = $this->article();
            $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertOk()->assertJsonPath('status', 'DRAFT');
            $this->act($this->admin, $a->slug, $action, $body)->assertOk();
        }
    }

    public function test_assigning_a_submitted_article_still_moves_it_to_under_review(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertOk()->assertJsonPath('status', 'UNDER_REVIEW');
        $this->assertSame(ReviewAction::STARTED_REVIEW, $this->lastReview($a)->action);
    }

    public function test_reassign_writes_reassigned_row_and_keeps_status(): void
    {
        $other = User::factory()->reporter()->create();
        ReporterCategoryAssignment::create(['reporter_id' => $other->id, 'category_id' => $this->category->id]);
        $a = $this->article(S::UNDER_REVIEW, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $other->id])->assertOk()->assertJsonPath('assigned_reporter.id', $other->id);
        $this->assertSame("Reassigned to reporter {$other->email} for review.", $this->lastReview($a)->reason);
        $this->assertSame(S::UNDER_REVIEW, $this->statusOf($a));
    }

    public function test_cannot_assign_non_reporter_unassigned_category_reporter_or_the_author(): void
    {
        $a = $this->article();
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->plainUser->id])->assertStatus(400)
            ->assertExactJson(['reporter' => 'The assigned user must be a Reporter (role=REPORTER).']);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->otherReporter->id])->assertStatus(400)
            ->assertJsonStructure(['reporter']);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->author->id])->assertStatus(400)
            ->assertExactJson(['reporter' => "The assigned reporter cannot be the article's author."]);
        $this->assertSame(S::DRAFT, $this->statusOf($a));
        $this->assertNull($a->fresh()->assigned_reporter_id);
    }

    public function test_cannot_assign_when_article_has_no_category(): void
    {
        $a = $this->article(S::DRAFT, ['subcategory_id' => null, 'category_id' => null]);
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertStatus(400)->assertJsonStructure(['reporter']);
    }

    // ------------------------------------------------------------------ service: changeStatus (the PATCH bypass is gone)

    public function test_change_status_follows_the_whole_map_and_nothing_else(): void
    {
        $svc = app(ArticleWorkflowService::class);
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $a = $this->article($from, ['assigned_reporter_id' => $this->assignee->id]);
                $ctx = ['reason' => 'because', 'scheduled_for' => now()->addDay()];
                $legal = $from->canTransitionTo($to);
                if ($from === $to) {
                    $this->assertSame($from, $svc->changeStatus($a, $this->admin, $to, $ctx)->status, "$from->value noop");
                    $this->assertSame(0, ArticleReview::where('article_id', $a->id)->count());

                    continue;
                }
                $actor = $to === S::SUBMITTED ? $this->author : $this->admin; // submit has no admin bypass
                if ($legal) {
                    $res = $svc->changeStatus($a, $actor, $to, $ctx);
                    $this->assertSame($to, $res->status, "{$from->value}->{$to->value}");
                    $this->assertSame($to, $a->fresh()->status);
                    $this->assertSame(1, ArticleReview::where('article_id', $a->id)->count(), "review row {$from->value}->{$to->value}");
                } else {
                    try {
                        $svc->changeStatus($a, $this->admin, $to, $ctx);
                        $this->fail("{$from->value}->{$to->value} must be refused");
                    } catch (WorkflowException $e) {
                        $this->assertSame(400, $e->httpStatus);
                        $this->assertSame($from, $a->fresh()->status);
                    }
                }
            }
        }
    }

    public function test_change_status_enforces_actor_permissions_like_the_action_endpoints(): void
    {
        $svc = app(ArticleWorkflowService::class);
        $a = $this->article(S::SUBMITTED);
        foreach ([$this->author, $this->otherReporter, $this->plainUser] as $actor) {
            try {
                $svc->changeStatus($a, $actor, S::APPROVED);
                $this->fail('non-privileged approve must be refused for '.$actor->role->value);
            } catch (AccessDeniedHttpException) {
                $this->assertSame(S::SUBMITTED, $a->fresh()->status);
            }
        }
        // admin cannot force PUBLISHED from SUBMITTED even though he is admin (map is not loosened)
        $this->expectException(WorkflowException::class);
        $svc->changeStatus($a, $this->admin, S::PUBLISHED);
    }

    public function test_change_status_requires_reason_and_datetime_context(): void
    {
        $svc = app(ArticleWorkflowService::class);
        $a = $this->article(S::SUBMITTED);
        foreach ([[S::REJECTED, []], [S::CHANGES_REQUESTED, ['reason' => ' ']], [S::SCHEDULED, []]] as [$to, $ctx]) {
            $b = $this->article($to === S::SCHEDULED ? S::APPROVED : S::SUBMITTED);
            try {
                $svc->changeStatus($b, $this->admin, $to, $ctx);
                $this->fail('context validation expected');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(S::SUBMITTED, $a->fresh()->status);
    }

    public function test_change_status_cannot_move_back_to_draft(): void
    {
        $this->expectException(WorkflowException::class);
        app(ArticleWorkflowService::class)->changeStatus($this->article(S::SUBMITTED), $this->admin, S::DRAFT);
    }

    // ------------------------------------------------------------------ author immutability / notifications

    public function test_author_never_changes_through_the_full_lifecycle(): void
    {
        $a = $this->article();
        $this->act($this->author, $a->slug, 'submit')->assertOk();
        $this->act($this->admin, $a->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertOk();
        $this->act($this->assignee, $a->slug, 'approve')->assertOk();
        $this->act($this->admin2, $a->slug, 'schedule', ['scheduled_for' => now()->addDay()->toIso8601String()])->assertOk();
        $this->act($this->admin2, $a->slug, 'publish')->assertOk();
        $this->assertSame($this->author->id, $a->fresh()->author_id);
        $this->assertNotSame($a->fresh()->author_id, $a->fresh()->assigned_reporter_id);
    }

    public function test_author_id_cannot_be_overwritten_by_saving_the_model(): void
    {
        $a = $this->article();
        $a->author_id = $this->admin->id;
        $a->save();
        $this->assertSame($this->author->id, $a->fresh()->author_id);
    }

    public function test_submit_notifies_admins_but_not_the_actor(): void
    {
        $admin3 = User::factory()->admin()->create();
        $a = $this->article();
        $this->act($this->author, $a->slug, 'submit')->assertOk();
        $n = Notification::where('notification_type', NotificationType::ARTICLE_SUBMITTED->value)->get();
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->admin2->id, $admin3->id], $n->pluck('recipient_id')->all());
        $this->assertSame("Article \"{$a->title}\" was submitted for review by {$this->author->email}.", $n[0]->message);
        $this->assertSame($a->id, $n[0]->article_id);
    }

    public function test_resubmit_notification_type_and_wording(): void
    {
        $a = $this->article(S::CHANGES_REQUESTED);
        $this->act($this->author, $a->slug, 'submit')->assertOk();
        $n = Notification::where('notification_type', NotificationType::ARTICLE_RESUBMITTED->value)->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('was resubmitted for review by', $n->message);
    }

    public function test_decision_notifications_go_to_the_author_with_django_wording(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->act($this->admin, $a->slug, 'request-changes', ['reason' => 'Add sources'])->assertOk();
        $this->assertSame("Changes were requested on your article \"{$a->title}\": Add sources", $this->notif(NotificationType::CHANGES_REQUESTED, $this->author)[0]->message);

        $b = $this->article(S::SUBMITTED);
        $this->act($this->admin, $b->slug, 'reject', ['reason' => 'Plagiarised'])->assertOk();
        $this->assertSame("Your article \"{$b->title}\" was rejected: Plagiarised", $this->notif(NotificationType::ARTICLE_REJECTED, $this->author)[0]->message);

        $c = $this->article(S::SUBMITTED);
        $this->act($this->admin, $c->slug, 'approve')->assertOk();
        $this->assertSame("Your article \"{$c->title}\" was approved.", $this->notif(NotificationType::ARTICLE_APPROVED, $this->author)[0]->message);

        $this->act($this->admin, $c->slug, 'publish')->assertOk();
        $this->assertSame("Your article \"{$c->title}\" was published.", $this->notif(NotificationType::ARTICLE_PUBLISHED, $this->author)[0]->message);
    }

    public function test_schedule_and_cancel_notifications(): void
    {
        $a = $this->article(S::APPROVED);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->addDay()->toIso8601String()])->assertOk();
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->addDays(2)->toIso8601String()])->assertOk();
        $msgs = $this->notif(NotificationType::ARTICLE_SCHEDULED, $this->author)->pluck('message')->all();
        $this->assertCount(2, $msgs);
        $this->assertStringContainsString('was scheduled to publish at', $msgs[0]);
        $this->assertStringContainsString('was rescheduled to publish at', $msgs[1]);
        $this->act($this->admin, $a->slug, 'cancel-schedule')->assertOk();
        $this->assertSame("The scheduled publish for your article \"{$a->title}\" was cancelled.", $this->notif(NotificationType::ARTICLE_SCHEDULE_CANCELLED, $this->author)[0]->message);
    }

    public function test_assigned_reporter_decision_still_notifies_the_author_not_the_reporter(): void
    {
        $a = $this->article(S::UNDER_REVIEW, ['assigned_reporter_id' => $this->assignee->id]);
        $this->act($this->assignee, $a->slug, 'approve')->assertOk();
        $this->assertCount(1, $this->notif(NotificationType::ARTICLE_APPROVED, $this->author));
        $this->assertCount(0, $this->notif(NotificationType::ARTICLE_APPROVED, $this->assignee));
    }

    public function test_review_row_records_from_to_reviewer_and_transaction_rolls_back_on_failure(): void
    {
        $a = $this->article(S::SUBMITTED);
        $this->act($this->admin, $a->slug, 'approve')->assertOk();
        $r = $this->lastReview($a);
        $this->assertSame('SUBMITTED', $r->from_status);
        $this->assertSame('APPROVED', $r->to_status);
        $this->assertSame($this->admin->id, $r->reviewer_id);

        // A failing notification must roll the whole transition back (status + review row).
        $b = $this->article(S::SUBMITTED);
        $this->app->bind(NotificationService::class, fn () => new class extends NotificationService
        {
            public function notify(?User $r, NotificationType $t, string $m, ?Article $a = null): ?Notification
            {
                throw new \RuntimeException('boom');
            }
        });
        $this->app->forgetInstance(ArticleWorkflowService::class);
        $this->app->singleton(ArticleWorkflowService::class);
        try {
            app(ArticleWorkflowService::class)->approve($b, $this->admin);
            $this->fail('expected failure');
        } catch (\RuntimeException) {
        }
        $this->assertSame(S::SUBMITTED, $b->fresh()->status);
        $this->assertSame(0, ArticleReview::where('article_id', $b->id)->count());
    }
}
