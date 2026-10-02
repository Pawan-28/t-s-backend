<?php

namespace Tests\Feature;

use App\Providers\OpsServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PortalDoctorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A healthy shared-hosting configuration (Profile A) on top of the test environment.
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.debug' => false, 'app.env' => 'production',
            'app.url' => 'https://api.portal.test', 'portal.frontend_url' => 'https://www.portal.test',
            'cors.allowed_origins' => ['https://www.portal.test'],
            'cache.default' => 'database', 'queue.default' => 'database', 'portal.analytics.driver' => 'database',
            'portal.queue_via_scheduler' => true, 'mail.default' => 'smtp',
            'database.redis.default.port' => 1, 'database.redis.cache.port' => 1, 'database.redis.analytics.port' => 1,
        ]);
        Cache::put(OpsServiceProvider::HEARTBEAT_KEY, now()->toIso8601String(), 600);
    }

    /** @return array{code:int,json:array,raw:string} */
    private function doctor(array $options = []): array
    {
        $out = new BufferedOutput;
        $code = Artisan::call('portal:doctor', ['--json' => true] + $options, $out);
        $raw = $out->fetch();

        return ['code' => $code, 'json' => json_decode($raw, true) ?? [], 'raw' => $raw];
    }

    private function st(array $run, string $check): ?string
    {
        foreach ($run['json']['checks'] ?? [] as $c) {
            if ($c['check'] === $check) {
                return $c['status'];
            }
        }

        return null;
    }

    private function statuses(array $run, string $check): array
    {
        return array_column(array_filter($run['json']['checks'] ?? [], fn ($c) => $c['check'] === $check), 'status');
    }

    public function test_healthy_shared_hosting_profile_reports_no_blocking_problem_and_never_touches_redis(): void
    {
        $r = $this->doctor();
        $this->assertSame(0, $r['code'], $r['raw']);
        $this->assertTrue($r['json']['ok']);
        $this->assertSame(0, $r['json']['failures']);
        foreach (['connectivity', 'server version', 'FULLTEXT search', 'migrations', 'default cache store', 'time_zone', 'heartbeat', 'APP_KEY', 'storage/logs'] as $check) {
            $this->assertSame('OK', $this->st($r, $check), "{$check}: ".$r['raw']);
        }
        $this->assertNull($this->st($r, 'default cache store Redis connection'));
        $this->assertNull($this->st($r, 'analytics Redis connection'));
        $this->assertSame('INFO', $this->st($r, 'ext-redis') === 'OK' ? 'INFO' : $this->st($r, 'ext-redis'));   // optional either way
    }

    public function test_text_output_has_sections_and_a_result_line(): void
    {
        $buf = new BufferedOutput;
        $code = Artisan::call('portal:doctor', [], $buf);
        $out = $buf->fetch();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('== PHP (CLI)', $out);
        $this->assertStringContainsString('== Database', $out);
        $this->assertStringContainsString('== Drivers', $out);
        $this->assertMatchesRegularExpression('/Result: 0 failure\(s\), \d+ warning\(s\)\. No blocking problems\./', $out);
    }

    public function test_missing_app_key_and_debug_in_production_are_blocking(): void
    {
        config(['app.key' => '', 'app.debug' => true]);
        $r = $this->doctor();
        $this->assertSame(1, $r['code']);
        $this->assertSame('FAIL', $this->st($r, 'APP_KEY'));
        $this->assertSame('FAIL', $this->st($r, 'APP_DEBUG'));
    }

    public function test_debug_outside_production_is_only_a_warning_and_strict_promotes_warnings(): void
    {
        config(['app.debug' => true, 'app.env' => 'staging']);
        $r = $this->doctor();
        $this->assertSame(0, $r['code']);
        $this->assertSame('WARN', $this->st($r, 'APP_DEBUG'));
        $this->assertSame(1, $this->doctor(['--strict' => true])['code']);
    }

    public function test_invalid_analytics_driver_is_blocking(): void
    {
        config(['portal.analytics.driver' => 'mongo']);
        $r = $this->doctor();
        $this->assertSame(1, $r['code']);
        $this->assertSame('FAIL', $this->st($r, 'ANALYTICS_DRIVER'));
    }

    public function test_selected_but_unreachable_redis_is_blocking_and_leaks_no_password(): void
    {
        config(['cache.default' => 'redis', 'queue.default' => 'redis', 'portal.analytics.driver' => 'redis',
            'database.redis.default.password' => 'sekret-pass-123', 'database.redis.cache.password' => 'sekret-pass-123', 'database.redis.analytics.password' => 'sekret-pass-123',
            'database.redis.default.host' => '127.0.0.1', 'database.redis.cache.host' => '127.0.0.1', 'database.redis.analytics.host' => '127.0.0.1']);
        app()->forgetInstance('redis');
        $r = $this->doctor();
        $this->assertSame(1, $r['code']);
        foreach (['default cache store Redis connection', 'queue Redis connection', 'analytics Redis connection'] as $check) {
            $this->assertSame('FAIL', $this->st($r, $check), $check);
        }
        $this->assertStringNotContainsString('sekret-pass-123', $r['raw']);
    }

    public function test_broken_cache_store_and_missing_queue_table_are_blocking(): void
    {
        config(['cache.stores.database.table' => 'no_such_cache']);
        $r = $this->doctor();
        $this->assertSame(1, $r['code']);
        $this->assertContains('FAIL', $this->statuses($r, 'default cache store'));

        config(['cache.stores.database.table' => 'cache', 'queue.connections.database.table' => 'no_such_jobs']);
        $r = $this->doctor();
        $this->assertSame(1, $r['code']);
        $this->assertSame('FAIL', $this->st($r, 'table no_such_jobs'));
    }

    public function test_array_cache_store_outside_testing_is_flagged_as_not_persisting(): void
    {
        config(['cache.default' => 'array']);
        $this->app['env'] = 'production';
        $r = $this->doctor();
        $this->assertContains('WARN', $this->statuses($r, 'default cache store'));
        $this->assertSame('WARN', $this->st($r, 'heartbeat'));
    }

    public function test_scheduler_heartbeat_states(): void
    {
        Cache::forget(OpsServiceProvider::HEARTBEAT_KEY);
        $this->assertSame('WARN', $this->st($this->doctor(), 'heartbeat'));

        Cache::put(OpsServiceProvider::HEARTBEAT_KEY, now()->subMinutes(20)->toIso8601String(), 3600);
        $stale = $this->doctor();
        $this->assertSame('FAIL', $this->st($stale, 'heartbeat'));
        $this->assertSame(1, $stale['code']);

        Cache::put(OpsServiceProvider::HEARTBEAT_KEY, now()->subSeconds(70)->toIso8601String(), 3600);
        $this->assertSame('OK', $this->st($this->doctor(), 'heartbeat'));
    }

    public function test_the_scheduled_heartbeat_closure_writes_the_key_the_doctor_reads(): void
    {
        Cache::forget(OpsServiceProvider::HEARTBEAT_KEY);
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'portal-scheduler-heartbeat');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $event->run($this->app);
        $this->assertNotNull(Cache::get(OpsServiceProvider::HEARTBEAT_KEY));
        $this->assertSame('OK', $this->st($this->doctor(), 'heartbeat'));
    }

    public function test_queue_backlog_and_failed_jobs_are_reported(): void
    {
        $this->assertSame('OK', $this->st($this->doctor(), 'backlog'));

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 2000, 'created_at' => time() - 2000]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        $r = $this->doctor();
        $this->assertSame('FAIL', $this->st($r, 'backlog'));
        $this->assertSame('WARN', $this->st($r, 'failed jobs'));
        $this->assertSame(1, $r['code']);
    }

    public function test_integrations_report_set_or_unset_only_and_never_print_values(): void
    {
        config([
            'portal.razorpay.key_id' => 'rzp_live_SECRETKEYID', 'portal.razorpay.key_secret' => 'RZP_SECRET_VALUE_XYZ', 'portal.razorpay.webhook_secret' => 'whsec_ABC',
            'portal.openai.api_key' => 'sk-live-OPENAI-VALUE', 'portal.wati.token' => 'WATI_TOKEN_VALUE', 'portal.wati.endpoint' => 'https://live-wati.example/api',
            'portal.bunny.api_key' => '', 'portal.bunny.storage_zone' => '', 'portal.bunny.pull_zone_url' => '',
            'portal.copyleaks.email' => 'me@x.io', 'portal.copyleaks.api_key' => '', 'portal.copyleaks.webhook_secret' => '', 'portal.copyleaks.webhook_base_url' => '',
            'database.connections.mysql.password' => 'DB_PASSWORD_VALUE',
        ]);
        $r = $this->doctor();
        $this->assertSame('OK', $this->st($r, 'Razorpay'));
        $this->assertSame('OK', $this->st($r, 'OpenAI'));
        $this->assertSame('OK', $this->st($r, 'WATI (WhatsApp)'));
        $this->assertSame('INFO', $this->st($r, 'Bunny.net storage'));
        $this->assertSame('WARN', $this->st($r, 'Copyleaks'));
        foreach (['rzp_live_SECRETKEYID', 'RZP_SECRET_VALUE_XYZ', 'whsec_ABC', 'sk-live-OPENAI-VALUE', 'WATI_TOKEN_VALUE', 'live-wati.example', 'DB_PASSWORD_VALUE', str_repeat('k', 32), base64_encode(str_repeat('k', 32))] as $secret) {
            $this->assertStringNotContainsString($secret, $r['raw']);
        }
    }

    public function test_it_is_read_only(): void
    {
        $tables = ['users', 'articles', 'jobs', 'failed_jobs', 'article_daily_views'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);
        $this->doctor();
        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables));
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%portal:doctor:%')->count(), 'probe keys are removed');
    }
}
