<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\Role;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\ArticleReview;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PhoneOtp;
use App\Models\PublishingSchedule;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Otp\OtpService;
use Database\Factories\ArticleImageFactory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Concerns\ArticleTestHelpers;
use Tests\Concerns\PaymentsTestHelpers;
use Tests\TestCase;

/**
 * REAL parallelism: every scenario spawns several PHP processes (tests/Support/MySqlConcurrencyWorker.php) that hit
 * InnoDB at the same instant on committed rows (hence DatabaseTruncation, no wrapping transaction). Guards the
 * idempotency of payment activation, publish-due, OTP attempt counting and featured-image switching under
 * row locks, plus the deadlock-retry contract (`DB::transaction($cb, 3)` survives error 1213).
 */
class MySqlConcurrencyTest extends TestCase
{
    use ArticleTestHelpers, DatabaseTruncation, PaymentsTestHelpers;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    /**
     * @param  list<array{0:string,1:array}>  $jobs  [mode, args] per worker
     * @return list<array<string,mixed>>
     */
    private function parallel(array $jobs): array
    {
        $cfg = config('database.connections.mysql');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_TIMEZONE' => config('app.timezone'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'],
            'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'BCRYPT_ROUNDS' => '4',
        ]);
        $start = microtime(true) + 3.0 + 0.15 * count($jobs); // every worker must have booted before the shared start
        $procs = [];
        foreach ($jobs as $i => [$mode, $args]) {
            $cmd = [PHP_BINARY, base_path('tests/Support/MySqlConcurrencyWorker.php'), $mode, sprintf('%.6F', $start), json_encode($args)];
            $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
            $this->assertIsResource($procs[$i]);
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            proc_close($p);
            $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
            $last = $lines ? json_decode(end($lines), true) : null;
            $this->assertIsArray($last, "worker {$i} produced no result. stdout={$stdout} stderr={$stderr}");
            $results[] = $last;
        }
        foreach ($results as $r) {
            $this->assertArrayNotHasKey('error', $r, 'worker failed: '.json_encode($r));
        }

        return $results;
    }

    public function test_concurrent_verify_and_webhook_activate_a_payment_exactly_once(): void
    {
        $this->configurePayments();
        $user = User::factory()->create();
        $plan = $this->plan(['price_amount' => '499.00', 'duration_days' => 30]);
        [$sub, $pay] = $this->pendingCheckout($user, $plan, 'order_RACE0000000001');
        $a = ['order_id' => $pay->razorpay_order_id, 'payment_id' => 'pay_RACE1', 'amount' => 49900];

        $results = $this->parallel([
            ['webhook', $a], ['webhook', $a], ['webhook', $a],
            ['verify', $a + ['user_id' => $user->id]], ['verify', $a + ['user_id' => $user->id]], ['webhook', $a],
        ]);

        $this->assertCount(6, $results);
        $pay->refresh();
        $sub->refresh();
        $this->assertSame(Payment::PAID, $pay->status);
        $this->assertSame('pay_RACE1', $pay->razorpay_payment_id);
        $this->assertSame(Subscription::ACTIVE, $sub->status);
        $this->assertNotNull($sub->started_at);
        $this->assertSame(30, (int) $sub->started_at->diffInDays($sub->expires_at), 'extended exactly once');
        $this->assertSame(Role::SUBSCRIBER, $user->refresh()->role);
        $this->assertSame(1, Notification::query()->where(['recipient_id' => $user->id, 'notification_type' => 'SUBSCRIPTION_ACTIVATED'])->count(), 'one activation notice');
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->count());
    }

    private function scheduledArticle(User $admin, int $minutesAgo = 1): array
    {
        $t = $this->tree();
        $art = Article::factory()->status(ArticleStatus::SCHEDULED)->create(['subcategory_id' => $t['subcategory']->id, 'scheduled_publish_at' => now()->subMinutes($minutesAgo)]);
        $sched = PublishingSchedule::query()->create(['article_id' => $art->id, 'scheduled_for' => now()->subMinutes($minutesAgo), 'scheduled_by_id' => $admin->id, 'status' => PublishingSchedule::PENDING]);

        return [$art, $sched];
    }

    public function test_publish_due_is_idempotent_across_parallel_workers(): void
    {
        $admin = User::factory()->admin()->create();
        [$art, $sched] = $this->scheduledArticle($admin);

        $results = $this->parallel(array_fill(0, 5, ['publish_due', ['schedule_id' => $sched->id]]));

        $this->assertSame(1, count(array_filter(array_column($results, 'result'), fn ($r) => $r === true)), 'exactly one worker published it');
        $art->refresh();
        $this->assertSame(ArticleStatus::PUBLISHED, $art->status);
        $this->assertNotNull($art->published_at);
        $this->assertNull($art->scheduled_publish_at);
        $this->assertSame(PublishingSchedule::EXECUTED, $sched->refresh()->status);
        $this->assertSame(1, ArticleReview::query()->where(['article_id' => $art->id, 'to_status' => 'PUBLISHED'])->count());
        $this->assertSame(1, Notification::query()->where(['recipient_id' => $art->author_id, 'notification_type' => 'ARTICLE_PUBLISHED'])->count());
    }

    public function test_publish_all_due_split_between_workers_publishes_every_article_once(): void
    {
        $admin = User::factory()->admin()->create();
        $arts = [];
        for ($i = 0; $i < 6; $i++) {
            $arts[] = $this->scheduledArticle($admin, 10 + $i)[0];
        }

        $results = $this->parallel(array_fill(0, 3, ['publish_all', []]));

        $this->assertSame(6, array_sum(array_column($results, 'result')), 'each due schedule was published by exactly one worker');
        foreach ($arts as $art) {
            $this->assertSame(ArticleStatus::PUBLISHED, $art->refresh()->status);
            $this->assertSame(1, ArticleReview::query()->where(['article_id' => $art->id, 'to_status' => 'PUBLISHED'])->count());
        }
        $this->assertSame(0, PublishingSchedule::query()->where('status', PublishingSchedule::PENDING)->count());
    }

    public function test_otp_attempts_are_counted_exactly_under_parallel_wrong_guesses_and_a_code_is_single_use(): void
    {
        $this->configurePayments();
        $user = User::factory()->create();
        $otp = fn (string $code) => PhoneOtp::query()->create([
            'user_id' => $user->id, 'phone' => '919876543210', 'code_hash' => app(OtpService::class)->hash($user->id, '919876543210', $code),
            'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'is_verified' => false,
        ]);
        $row = $otp('123456');

        $results = $this->parallel(array_fill(0, 8, ['otp_verify', ['user_id' => $user->id, 'code' => '000000']]));
        $msgs = array_count_values(array_column($results, 'result'));
        $this->assertSame(5, $msgs['Incorrect code.'] ?? 0, 'no lost attempt increments');
        $this->assertSame(3, $msgs['Too many incorrect attempts. Request a new code.'] ?? 0);
        $this->assertSame(5, $row->refresh()->attempts);

        // even the right code is refused once the attempt budget is spent
        $late = $this->parallel([['otp_verify', ['user_id' => $user->id, 'code' => '123456']]]);
        $this->assertSame('Too many incorrect attempts. Request a new code.', $late[0]['result']);

        // a fresh code: three parallel correct submissions -> one wins, the others see no pending OTP
        $row->delete();
        $fresh = $otp('654321');
        $results = $this->parallel(array_fill(0, 3, ['otp_verify', ['user_id' => $user->id, 'code' => '654321']]));
        $msgs = array_count_values(array_column($results, 'result'));
        $this->assertSame(1, $msgs['ok'] ?? 0);
        $this->assertSame(2, $msgs['No pending OTP request found. Request a new code.'] ?? 0);
        $this->assertTrue($fresh->refresh()->is_verified);
        $this->assertSame('919876543210', $user->refresh()->phone);
    }

    public function test_parallel_featured_image_switching_always_leaves_exactly_one_featured(): void
    {
        $t = $this->tree();
        $art = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id]);
        $imgs = [];
        foreach ([true, false, false] as $i => $featured) {
            $imgs[] = ArticleImageFactory::new()->create(['article_id' => $art->id, 'uploaded_by_id' => $art->author_id, 'is_featured' => $featured, 'display_order' => $i]);
        }

        $this->parallel([
            ['featured', ['image_id' => $imgs[1]->id]], ['featured', ['image_id' => $imgs[2]->id]], ['featured', ['image_id' => $imgs[0]->id]],
            ['featured', ['image_id' => $imgs[2]->id]],
        ]);

        $this->assertSame(1, ArticleImage::query()->where(['article_id' => $art->id, 'is_featured' => true])->count());
    }

    public function test_a_real_deadlock_is_survived_by_the_transaction_retry(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        $results = $this->parallel([
            ['deadlock', ['first' => $u1->id, 'second' => $u2->id]],
            ['deadlock', ['first' => $u2->id, 'second' => $u1->id]],
        ]);

        $this->assertSame(['done', 'done'], array_column($results, 'result'), 'both transactions committed');
        $this->assertGreaterThanOrEqual(3, array_sum(array_column($results, 'attempts')), 'InnoDB picked a victim (1213) and the retry re-ran it');
    }
}
