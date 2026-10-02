<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus as S;
use App\Jobs\FlushArticleViews;
use App\Jobs\ReindexArticleSearch;
use App\Jobs\SendWatiTemplateMessage;
use App\Mail\SubscriptionNoticeMail;
use App\Models\PublishingSchedule;
use App\Models\User;
use Database\Factories\SubscriptionFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/** Shared-hosting queue + scheduler: every job works with QUEUE_CONNECTION=database (drained by a cron-run worker) and sync. */
class QueueSchedulerProfileTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
        config([
            'queue.default' => 'database', 'cache.default' => 'database', 'portal.analytics.driver' => 'database',
            'mail.default' => 'array',
            'portal.wati.endpoint' => 'https://wati.test', 'portal.wati.token' => 'tok', 'portal.wati.templates.article_published' => 'new_article',
            'database.redis.default.port' => 1, 'database.redis.cache.port' => 1,
        ]);
        Http::fake(['*' => Http::response(['result' => true], 200)]);
    }

    private function jobs(): int
    {
        return DB::table('jobs')->count();
    }

    /** The scheduler's command (test uses --sleep=0: the real one idles 3s after the last job before exiting, which is harmless). */
    private function drain(): int
    {
        return Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3, '--sleep' => 0]);
    }

    private function scheduleEvents(): Collection
    {
        $this->app->forgetInstance(Schedule::class);   // re-run the providers' callAfterResolving with the current config

        return collect(app(Schedule::class)->events());
    }

    private function byName(string $name): ?Event
    {
        return $this->scheduleEvents()->first(fn (Event $e) => $e->description === $name);
    }

    // ------------------------------------------------------------------ database queue: dispatch + cron-driven drain

    public function test_wati_job_waits_in_the_jobs_table_and_is_sent_by_the_cron_worker(): void
    {
        SendWatiTemplateMessage::dispatch('9876543210', 'article_published', [['name' => 'title', 'value' => 'Hi']]);
        $this->assertSame(1, $this->jobs());
        Http::assertNothingSent();

        $this->assertSame(0, $this->drain());
        $this->assertSame(0, $this->jobs());
        Http::assertSentCount(1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_queued_mailable_is_sent_after_the_drain(): void
    {
        Mail::to('reader@example.com')->send(new SubscriptionNoticeMail('Subject X', 'Body Y'));
        $this->assertSame(1, $this->jobs());
        $transport = fn () => Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(0, $transport());

        $this->drain();
        $this->assertSame(0, $this->jobs());
        $this->assertCount(1, $transport());
        $this->assertStringContainsString('Subject X', $transport()[0]->getOriginalMessage()->getSubject());
    }

    public function test_search_reindex_job_runs_from_the_queue(): void
    {
        $a = $this->article(S::PUBLISHED, ['published_at' => now()]);
        DB::table('article_search_index')->where('article_id', $a->id)->delete();
        ReindexArticleSearch::dispatch('tag', 0, [$a->id]);
        $this->assertSame(1, $this->jobs());
        $this->assertSame(0, DB::table('article_search_index')->where('article_id', $a->id)->count());

        $this->drain();
        $this->assertSame(1, DB::table('article_search_index')->where('article_id', $a->id)->count());
    }

    public function test_scheduled_publish_then_subscriber_notification_chain_through_the_database_queue(): void
    {
        SubscriptionFactory::new()->active()->create(['user_id' => User::factory()->create(['phone' => '919800000001'])->id, 'contact_email' => 'sub@example.com']);
        $a = $this->article(S::SCHEDULED, ['access_level' => AccessLevel::SUBSCRIBER_ONLY, 'scheduled_publish_at' => now()->subMinute()]);
        PublishingSchedule::create(['article_id' => $a->id, 'scheduled_for' => now()->subMinute(), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);

        // What the every-minute scheduler entry runs:
        $this->assertSame(0, Artisan::call('articles:publish-due'));
        $this->assertSame(S::PUBLISHED, $a->fresh()->status);
        $this->assertTrue(DB::table('jobs')->where('payload', 'like', '%NotifySubscribersOfArticle%')->exists());
        $this->assertNull($a->fresh()->subscribers_notified_at);

        // Fan-out sends WhatsApp inline and queues the e-mail (a second job): two drains' worth, one worker run.
        $this->drain();
        $this->assertNotNull($a->fresh()->subscribers_notified_at);
        $this->assertSame(0, $this->jobs());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Http::assertSentCount(1);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_analytics_flush_job_is_harmless_on_the_database_driver(): void
    {
        FlushArticleViews::dispatch();
        $this->assertSame(1, $this->jobs());
        $this->drain();
        $this->assertSame(0, $this->jobs());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_failing_job_is_retried_then_lands_in_failed_jobs(): void
    {
        AlwaysFailsJob::dispatch();
        $this->assertSame(0, $this->drain());   // the worker itself does not crash

        $this->assertSame(0, $this->jobs());
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertSame('database', $failed[0]->connection);
        $this->assertStringContainsString('always fails', $failed[0]->exception);
        $this->assertSame(3, AlwaysFailsJob::$attempts, 'default --tries=3 applies to jobs without their own $tries');
    }

    public function test_a_job_with_its_own_tries_keeps_it(): void
    {
        SingleTryFailsJob::dispatch();
        $this->drain();
        $this->assertSame(1, SingleTryFailsJob::$attempts);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_sync_connection_runs_everything_inline_with_no_worker(): void
    {
        config(['queue.default' => 'sync']);
        SendWatiTemplateMessage::dispatch('9876543210', 'article_published', []);
        Mail::to('reader@example.com')->send(new SubscriptionNoticeMail('S', 'B'));

        Http::assertSentCount(1);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertSame(0, $this->jobs());
    }

    public function test_the_default_retry_after_exceeds_the_longest_job_timeout(): void
    {
        $this->assertGreaterThan((new FlushArticleViews)->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan((new FlushArticleViews)->timeout, config('queue.connections.redis.retry_after'));
    }

    // ------------------------------------------------------------------ scheduler wiring

    public function test_queue_worker_is_scheduled_only_when_queue_via_scheduler_is_on(): void
    {
        config(['portal.queue_via_scheduler' => false]);
        $this->assertNull($this->byName('queue-work-via-scheduler'), 'VPS with a supervisor daemon must be unaffected');

        config(['portal.queue_via_scheduler' => true]);
        $e = $this->byName('queue-work-via-scheduler');
        $this->assertNotNull($e);
        $this->assertSame('* * * * *', $e->expression);
        $this->assertStringContainsString('queue:work database --stop-when-empty --max-time=50 --tries=3', $e->command);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertSame(5, $e->expiresAt);
        $this->assertSame('queue-work-via-scheduler', $this->scheduleEvents()->last()->description, 'runs after every other task of the tick so it drains what they queued');

        config(['queue.default' => 'sync']);
        $this->assertNull($this->byName('queue-work-via-scheduler'), 'nothing to drain with sync');

        config(['queue.default' => 'redis', 'portal.queue_via_scheduler_connection' => null]);
        $this->assertStringContainsString('queue:work redis ', $this->byName('queue-work-via-scheduler')->command);
    }

    public function test_every_domain_task_is_scheduled_with_the_expected_cadence(): void
    {
        $expected = [
            'articles:publish-due' => '* * * * *',            // scheduled publishing within ~1 minute
            'subscriptions:notify-published' => '*/5 * * * *',
            'subscriptions:expire' => '0 * * * *',
            'subscriptions:remind-expiring' => '0 * * * *',
            'plagiarism-expire-pending' => '*/15 * * * *',
            'portal-scheduler-heartbeat' => '* * * * *',
            'sanctum-prune-expired' => '10 3 * * *',
            'queue-prune-failed' => '20 3 * * *',
            'portal-prune-cache' => '0 * * * *',
        ];
        $events = $this->scheduleEvents();
        foreach ($expected as $needle => $cron) {
            $e = $events->first(fn (Event $e) => $e->description === $needle || str_contains((string) $e->command, $needle));
            $this->assertNotNull($e, $needle);
            $this->assertSame($cron, $e->expression, $needle);
        }
        $this->assertNotNull($this->byName('analytics-flush-views'));
    }

    public function test_overlap_and_one_server_mutexes_work_on_the_database_and_file_cache_stores(): void
    {
        $event = $this->scheduleEvents()->first(fn (Event $e) => str_contains((string) $e->command, 'articles:publish-due'));
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);

        foreach (['database', 'file'] as $store) {
            config(['cache.default' => $store]);
            $mutex = $event->mutex;
            $mutex->forget($event);
            $this->assertTrue($mutex->create($event), "{$store}: first run takes the mutex");
            $this->assertFalse($mutex->create($event), "{$store}: an overlapping run is refused");
            $this->assertTrue($mutex->exists($event));
            $mutex->forget($event);
            $this->assertFalse($mutex->exists($event));

            $server = $this->app->make(CacheSchedulingMutex::class);
            $time = now()->startOfMinute();
            $release = fn () => Cache::store($store)->getStore()->lock($event->mutexName().$time->format('Hi'), 3600)->forceRelease();
            $release();
            $this->assertTrue($server->create($event, $time), "{$store}: onOneServer claim");
            $this->assertFalse($server->create($event, $time), "{$store}: second claim in the same minute is refused");
            $this->assertTrue($server->exists($event, $time));
            $release();
        }
    }
}

class AlwaysFailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static int $attempts = 0;

    public function handle(): void
    {
        self::$attempts++;
        throw new \RuntimeException('always fails');
    }
}

class SingleTryFailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public static int $attempts = 0;

    public function handle(): void
    {
        self::$attempts++;
        throw new \RuntimeException('once');
    }
}
