<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\PublishingSchedule;
use App\Models\User;
use App\Services\Analytics\AnalyticsReportingService;
use App\Services\Workflow\ArticleWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/**
 * DATETIME columns hold the WALL CLOCK of the app time zone; the DB session time zone is the matching numeric offset
 * (config/database.php). Run the suite with APP_TIMEZONE=Asia/Kolkata (default) and APP_TIMEZONE=UTC: both must pass.
 */
class MySqlTimezoneTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_db_session_clock_equals_app_clock(): void
    {
        $tz = config('app.timezone');
        $offset = Carbon::now($tz)->format('P');
        $this->assertSame($offset, config('database.connections.mysql.timezone'));
        $this->assertSame($offset, DB::selectOne('SELECT @@session.time_zone AS tz')->tz);

        $dbNow = Carbon::parse(DB::selectOne('SELECT NOW() AS n')->n, $tz);
        $this->assertLessThan(5, abs($dbNow->getTimestamp() - Carbon::now()->getTimestamp()), 'NOW() and PHP now() are the same instant');
    }

    public function test_db_defaults_current_timestamp_and_php_values_agree(): void
    {
        $user = User::factory()->create();
        $id = DB::table('notifications')->insertGetId(['recipient_id' => $user->id, 'notification_type' => 'ARTICLE_PUBLISHED', 'message' => 'x', 'is_read' => false]); // created_at DEFAULT CURRENT_TIMESTAMP
        $created = Carbon::parse(DB::table('notifications')->where('id', $id)->value('created_at'), config('app.timezone'));
        $this->assertLessThan(5, abs($created->getTimestamp() - now()->getTimestamp()));
        // comparing a DB-defaulted column against a PHP-bound value works in the same clock
        $this->assertSame(1, DB::table('notifications')->where('id', $id)->where('created_at', '<=', now()->addSecond())->where('created_at', '>=', now()->subMinute())->count());
    }

    public function test_wall_clock_storage_and_iso_output_are_consistent(): void
    {
        $tz = config('app.timezone');
        $instant = Carbon::parse('2026-03-29 01:30:00', 'Asia/Kolkata');   // a fixed instant
        // Laravel writes a Carbon's OWN wall clock (no zone conversion): app code always hands models app-zone Carbons.
        $a = Article::factory()->published()->create(['published_at' => $instant->copy()->setTimezone($tz)]);
        $raw = DB::table('articles')->where('id', $a->id)->value('published_at');
        $this->assertSame($instant->copy()->setTimezone($tz)->format('Y-m-d H:i:s'), $raw, 'stored as app-zone wall clock');
        $t = $this->tree();
        $a->update(['subcategory_id' => $t['subcategory']->id]);
        $iso = $this->as(null)->getJson('/api/articles/'.$a->slug.'/')->json('published_at');
        $this->assertSame($instant->getTimestamp(), Carbon::parse($iso)->getTimestamp(), 'API instant unchanged');
        $this->assertSame($instant->copy()->setTimezone($tz)->format('P'), Carbon::parse($iso)->format('P'), 'ISO carries the app-zone offset');
    }

    public function test_due_schedules_are_selected_in_the_same_clock_as_they_were_written(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $mk = fn (int $minutes) => tap(Article::factory()->status(ArticleStatus::SCHEDULED)->create(['subcategory_id' => $t['subcategory']->id, 'scheduled_publish_at' => now()->addMinutes($minutes)]),
            fn ($a) => PublishingSchedule::query()->create(['article_id' => $a->id, 'scheduled_for' => now()->addMinutes($minutes), 'scheduled_by_id' => $admin->id, 'status' => 'PENDING']));
        $soon = $mk(2);
        $later = $mk(600); // 10 h ahead: would be "due" if the DB and PHP clocks were 5.5 h skewed the wrong way

        $this->assertSame(0, app(ArticleWorkflowService::class)->publishDueSchedules());
        $this->assertSame(1, app(ArticleWorkflowService::class)->publishDueSchedules(now()->addMinutes(3)));
        $this->assertSame(ArticleStatus::PUBLISHED, $soon->refresh()->status);
        $this->assertSame(ArticleStatus::SCHEDULED, $later->refresh()->status);
    }

    public function test_api_schedule_with_zulu_and_offset_inputs_stores_the_right_instant(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $target = now()->addDays(2)->startOfMinute();
        foreach ([$target->copy()->utc()->format('Y-m-d\TH:i:s\Z'), $target->copy()->setTimezone('America/New_York')->format('Y-m-d\TH:i:sP'), $target->copy()->format('Y-m-d\TH:i:sP')] as $input) {
            $a = Article::factory()->status(ArticleStatus::APPROVED)->create(['subcategory_id' => $t['subcategory']->id]);
            $this->as($admin)->postJson('/api/articles/'.$a->slug.'/schedule/', ['scheduled_for' => $input])->assertOk();
            $stored = Carbon::parse(DB::table('articles')->where('id', $a->id)->value('scheduled_publish_at'), config('app.timezone'));
            $this->assertSame($target->getTimestamp(), $stored->getTimestamp(), $input);
        }
    }

    public function test_daily_buckets_use_the_app_zone_date(): void
    {
        $admin = User::factory()->admin()->create();
        $tz = config('app.timezone');
        $late = Carbon::now($tz)->subDay()->setTime(23, 30);            // 23:30 app-zone yesterday
        Article::factory()->published()->create(['published_at' => $late]);
        $rows = AnalyticsReportingService::publishingActivity(3)['published_last_n_days'];
        $this->assertSame([['date' => $late->format('Y-m-d'), 'count' => 1]], $rows);
        DB::table('article_daily_views')->insert(['article_id' => Article::query()->value('id'), 'date' => now()->toDateString(), 'views' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(now()->toDateString(), AnalyticsReportingService::viewsOverTime(1)[0]['date']);
        $this->assertNotNull($admin->id);
    }
}
