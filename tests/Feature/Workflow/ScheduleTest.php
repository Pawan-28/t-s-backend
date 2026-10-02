<?php

namespace Tests\Feature\Workflow;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus as S;
use App\Enums\NotificationType;
use App\Enums\ReviewAction;
use App\Jobs\NotifySubscribersOfArticle;
use App\Jobs\PublishDueSchedules;
use App\Mail\SubscriptionNoticeMail;
use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\Notification;
use App\Models\PublishingSchedule;
use App\Models\Subscription;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Workflow\ArticleWorkflowService;
use Database\Factories\SubscriptionFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
    }

    private function scheduled(int $minutesFromNow, array $attrs = []): array
    {
        $a = $this->article(S::SCHEDULED, ['scheduled_publish_at' => now()->addMinutes($minutesFromNow)] + $attrs);
        $s = PublishingSchedule::create(['article_id' => $a->id, 'scheduled_for' => now()->addMinutes($minutesFromNow), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);

        return [$a, $s];
    }

    private function pending(Article $a): int
    {
        return PublishingSchedule::where('article_id', $a->id)->where('status', 'PENDING')->count();
    }

    // ------------------------------------------------------------------ create / replace / cancel

    public function test_schedule_creates_exactly_one_pending_row_and_reschedule_updates_it(): void
    {
        Queue::fake();
        $a = $this->article(S::APPROVED);
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->addHours(2)->toIso8601String()])->assertOk();
        $first = PublishingSchedule::firstOrFail();
        $this->assertSame($this->admin->id, $first->scheduled_by_id);

        $this->act($this->admin2, $a->slug, 'schedule', ['scheduled_for' => now()->addHours(5)->toIso8601String()])->assertOk();
        $this->assertSame(1, PublishingSchedule::count());
        $this->assertSame(1, $this->pending($a));
        $second = PublishingSchedule::firstOrFail();
        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->admin2->id, $second->scheduled_by_id);
        $this->assertTrue($second->scheduled_for->greaterThan($first->scheduled_for));
        $this->assertTrue($a->fresh()->scheduled_publish_at->equalTo($second->scheduled_for));
        $this->assertSame([ReviewAction::RESCHEDULED, ReviewAction::SCHEDULED], ArticleReview::where('article_id', $a->id)->orderByDesc('id')->get()->pluck('action')->all());
    }

    public function test_database_refuses_a_second_pending_schedule_for_one_article(): void
    {
        [$a] = $this->scheduled(60);
        $this->expectException(QueryException::class);
        PublishingSchedule::create(['article_id' => $a->id, 'scheduled_for' => now()->addDay(), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);
    }

    public function test_history_keeps_cancelled_rows_and_a_new_schedule_can_follow(): void
    {
        Queue::fake();
        $a = $this->article(S::APPROVED);
        $at = fn (int $h) => ['scheduled_for' => now()->addHours($h)->toIso8601String()];
        $this->act($this->admin, $a->slug, 'schedule', $at(1))->assertOk();
        $this->act($this->admin, $a->slug, 'cancel-schedule')->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->act($this->admin, $a->slug, 'schedule', $at(3))->assertOk();
        $this->assertSame(['CANCELLED', 'PENDING'], PublishingSchedule::where('article_id', $a->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_publish_now_from_scheduled_cancels_the_pending_schedule(): void
    {
        Queue::fake();
        [$a] = $this->scheduled(60);
        $this->act($this->admin, $a->slug, 'publish')->assertOk();
        $this->assertSame(0, $this->pending($a));
        $this->assertSame('CANCELLED', PublishingSchedule::first()->status);
    }

    public function test_past_or_present_schedule_is_refused_everywhere(): void
    {
        $a = $this->article(S::APPROVED);
        $svc = app(ArticleWorkflowService::class);
        foreach ([now()->subSecond(), now()->subDay()] as $when) {
            try {
                $svc->schedule($a, $this->admin, $when);
                $this->fail('past schedule accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('scheduled_for', $e->errors());
            }
        }
        $this->assertSame(S::APPROVED, $a->fresh()->status);
        $this->assertSame(0, PublishingSchedule::count());
    }

    // ------------------------------------------------------------------ automatic publishing

    public function test_due_schedule_is_published_with_full_side_effects(): void
    {
        Queue::fake();
        [$a, $s] = $this->scheduled(-5);
        $this->assertSame(1, app(ArticleWorkflowService::class)->publishDueSchedules());

        $fresh = $a->fresh();
        $this->assertSame(S::PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_at);
        $this->assertNull($fresh->scheduled_publish_at);
        $this->assertSame($this->author->id, $fresh->author_id);
        $s->refresh();
        $this->assertSame('EXECUTED', $s->status);
        $this->assertNotNull($s->executed_at);
        $r = ArticleReview::where('article_id', $a->id)->sole();
        $this->assertSame(ReviewAction::PUBLISHED, $r->action);
        $this->assertNull($r->reviewer_id);
        $this->assertSame('SCHEDULED', $r->from_status);
        $n = Notification::where('notification_type', NotificationType::ARTICLE_PUBLISHED->value)->sole();
        $this->assertSame($this->author->id, $n->recipient_id);
        $this->assertSame("Your article \"{$a->title}\" was automatically published as scheduled.", $n->message);
    }

    public function test_future_schedule_is_not_published(): void
    {
        [$a, $s] = $this->scheduled(30);
        $this->assertSame(0, app(ArticleWorkflowService::class)->publishDueSchedules());
        $this->assertSame(S::SCHEDULED, $a->fresh()->status);
        $this->assertSame('PENDING', $s->fresh()->status);
        $this->assertSame(0, ArticleReview::count());
    }

    public function test_running_twice_publishes_once(): void
    {
        Queue::fake();
        [$a] = $this->scheduled(-1);
        $svc = app(ArticleWorkflowService::class);
        $this->assertSame(1, $svc->publishDueSchedules());
        $this->assertSame(0, $svc->publishDueSchedules());
        $this->artisan('articles:publish-due')->expectsOutput('Published 0 article(s).')->assertSuccessful();
        $this->assertSame(1, ArticleReview::where('article_id', $a->id)->count());
        $this->assertSame(1, Notification::where('notification_type', 'ARTICLE_PUBLISHED')->count());
    }

    public function test_second_worker_holding_a_stale_schedule_id_does_nothing(): void
    {
        Queue::fake();
        [$a, $s] = $this->scheduled(-1);
        $svc = app(ArticleWorkflowService::class);
        $this->assertTrue($svc->publishDueSchedule($s->id));
        $publishedAt = $a->fresh()->published_at;
        $this->assertFalse($svc->publishDueSchedule($s->id)); // stale id from a concurrent worker's earlier batch query
        $this->assertTrue($publishedAt->equalTo($a->fresh()->published_at));
        $this->assertSame(1, ArticleReview::where('article_id', $a->id)->count());
    }

    public function test_publishing_takes_row_locks_on_article_and_schedule(): void
    {
        Queue::fake();
        [$a, $s] = $this->scheduled(-1);
        DB::enableQueryLog();
        app(ArticleWorkflowService::class)->publishDueSchedule($s->id);
        $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains(strtolower($q), 'for update'));
        $this->assertGreaterThanOrEqual(2, $locks->count());
        $this->assertTrue($locks->contains(fn ($q) => str_contains($q, '`articles`')));
        $this->assertTrue($locks->contains(fn ($q) => str_contains($q, '`publishing_schedules`')));
    }

    public function test_manual_publish_then_job_does_not_publish_again(): void
    {
        Queue::fake();
        [$a] = $this->scheduled(-1);
        $this->act($this->admin, $a->slug, 'publish')->assertOk();
        $this->assertSame(0, app(ArticleWorkflowService::class)->publishDueSchedules());
        $this->assertSame(1, ArticleReview::where('article_id', $a->id)->count());
    }

    public function test_cancelled_schedule_is_never_executed(): void
    {
        Queue::fake();
        [$a] = $this->scheduled(-1);
        PublishingSchedule::where('article_id', $a->id)->update(['status' => 'CANCELLED']);
        $this->assertSame(0, app(ArticleWorkflowService::class)->publishDueSchedules());
        $this->assertSame(S::SCHEDULED, $a->fresh()->status);
    }

    public function test_out_of_sync_schedule_is_reconciled_to_cancelled_not_published(): void
    {
        [$a, $s] = $this->scheduled(-1);
        $a->forceFill(['status' => S::APPROVED])->save(); // article no longer SCHEDULED
        $this->assertSame(0, app(ArticleWorkflowService::class)->publishDueSchedules());
        $this->assertSame(S::APPROVED, $a->fresh()->status);
        $this->assertSame('CANCELLED', $s->fresh()->status);
    }

    public function test_one_failing_article_does_not_block_the_rest(): void
    {
        Queue::fake();
        [$bad] = $this->scheduled(-10);
        [$good] = $this->scheduled(-5);
        // make the first one blow up inside the transaction: its author's notification fails via a poisoned title
        $this->app->bind(NotificationService::class, fn () => new class extends NotificationService
        {
            public function notify(?User $r, NotificationType $t, string $m, ?Article $a = null): ?Notification
            {
                if (str_contains($m, 'POISON')) {
                    throw new \RuntimeException('boom');
                }

                return parent::notify($r, $t, $m, $a);
            }
        });
        $this->app->forgetInstance(ArticleWorkflowService::class);
        $this->app->singleton(ArticleWorkflowService::class);
        $bad->forceFill(['title' => 'POISON'])->save();

        $this->assertSame(1, app(ArticleWorkflowService::class)->publishDueSchedules());
        $this->assertSame(S::SCHEDULED, $bad->fresh()->status);           // rolled back, will retry next tick
        $this->assertSame('PENDING', PublishingSchedule::where('article_id', $bad->id)->value('status'));
        $this->assertSame(S::PUBLISHED, $good->fresh()->status);
    }

    public function test_job_and_command_entry_points(): void
    {
        Queue::fake();
        [$a] = $this->scheduled(-2);
        (new PublishDueSchedules)->handle(app(ArticleWorkflowService::class));
        $this->assertSame(S::PUBLISHED, $a->fresh()->status);
        [$b] = $this->scheduled(-2);
        $this->artisan('articles:publish-due')->expectsOutput('Published 1 article(s).')->assertSuccessful();
        $this->assertSame(S::PUBLISHED, $b->fresh()->status);
    }

    public function test_rescheduled_to_later_between_batch_and_lock_is_not_published(): void
    {
        Queue::fake();
        [$a, $s] = $this->scheduled(-1);
        $svc = app(ArticleWorkflowService::class);
        // an admin pushes it out after the batch query picked its id up
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => now()->addHours(4)->toIso8601String()])->assertOk();
        $this->assertFalse($svc->publishDueSchedule($s->id));
        $this->assertSame(S::SCHEDULED, $a->fresh()->status);
    }

    public function test_scheduler_runs_publish_due_every_minute_without_overlap_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'articles:publish-due'));
        $this->assertNotNull($event, 'articles:publish-due is not scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    // ------------------------------------------------------------------ subscriber fan-out

    public function test_publishing_a_non_public_article_dispatches_the_subscriber_job_once(): void
    {
        Queue::fake();
        $a = $this->article(S::APPROVED, ['access_level' => AccessLevel::SUBSCRIBER_ONLY]);
        $this->act($this->admin, $a->slug, 'publish')->assertOk();
        Queue::assertPushed(NotifySubscribersOfArticle::class, 1);

        $b = $this->article(S::APPROVED); // PUBLIC: nothing to tell subscribers
        $this->act($this->admin, $b->slug, 'publish')->assertOk();
        Queue::assertPushed(NotifySubscribersOfArticle::class, 1);
    }

    public function test_auto_publish_of_non_public_article_also_dispatches_it(): void
    {
        Queue::fake();
        [$a] = $this->scheduled(-1, ['access_level' => AccessLevel::RESTRICTED]);
        app(ArticleWorkflowService::class)->publishDueSchedules();
        Queue::assertPushed(NotifySubscribersOfArticle::class, fn ($j) => $j->articleId === $a->id);
    }

    public function test_subscriber_job_notifies_active_subscribers_once(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response(['result' => true], 200)]);
        config(['portal.wati.endpoint' => 'https://wati.test', 'portal.wati.token' => 'tok', 'portal.wati.templates.article_published' => 'new_article']);

        $active = SubscriptionFactory::new()->active()->create(['user_id' => User::factory()->create(['phone' => '919800000001'])->id, 'contact_email' => 'sub@example.com']);
        SubscriptionFactory::new()->expiresAt(now()->subDay())->create();
        SubscriptionFactory::new()->create(); // PENDING
        $a = $this->article(S::PUBLISHED, ['access_level' => AccessLevel::SUBSCRIBER_ONLY, 'published_at' => now()]);

        (new NotifySubscribersOfArticle($a->id))->handle();
        (new NotifySubscribersOfArticle($a->id))->handle(); // second run: already claimed

        $this->assertNotNull($a->fresh()->subscribers_notified_at);
        $this->drainQueue();
        Mail::assertQueued(SubscriptionNoticeMail::class, 1);
        Http::assertSentCount(1);
        $this->assertSame($active->id, Subscription::where('contact_email', 'sub@example.com')->value('id'));
    }

    public function test_subscriber_job_skips_public_unpublished_and_already_notified_articles(): void
    {
        Mail::fake();
        SubscriptionFactory::new()->active()->create();
        $public = $this->article(S::PUBLISHED, ['published_at' => now()]);
        $draft = $this->article(S::DRAFT, ['access_level' => AccessLevel::RESTRICTED]);
        $done = $this->article(S::PUBLISHED, ['access_level' => AccessLevel::RESTRICTED, 'published_at' => now(), 'subscribers_notified_at' => now()]);
        foreach ([$public, $draft, $done] as $a) {
            (new NotifySubscribersOfArticle($a->id))->handle();
        }
        Mail::assertNothingQueued();
        $this->assertNull($public->fresh()->subscribers_notified_at);
        $this->assertNull($draft->fresh()->subscribers_notified_at);
    }
}
