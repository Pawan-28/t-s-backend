<?php

namespace Tests\Feature\Import;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\LegacyFixture;
use Tests\TestCase;

/**
 * Runs the importer against the REAL Django-migrated scratch database (`django_scratch`, built and populated with
 * SYNTHETIC dirty data by /home/claude/briefs/agent6/reset_and_populate.sh). Skipped when it does not exist.
 */
class ImportScratchDatabaseTest extends TestCase
{
    use DatabaseTruncation; // the importer's AUTO_INCREMENT reset is DDL (implicit commit): no wrapping transaction

    private const SCRATCH = 'django_scratch';

    private array $legacyCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = LegacyFixture::settings(); // PostgreSQL server (the default connection is MySQL)
        try {
            $pdo = new PDO("pgsql:host={$cfg['host']};port={$cfg['port']};dbname=".self::SCRATCH, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $n = (int) $pdo->query("SELECT count(*) FROM accounts_user WHERE last_name = 'Admin' OR email LIKE '%@synthetic.test'")->fetchColumn();
            foreach (['accounts_user', 'articles_article', 'subscriptions_subscription', 'media_articleimage'] as $t) {
                $this->legacyCounts[$t] = (int) $pdo->query("SELECT count(*) FROM {$t}")->fetchColumn();
            }
        } catch (\Throwable) {
            $this->markTestSkipped('django_scratch database is not available.');
        }
        if ($n < 10) {
            $this->markTestSkipped('django_scratch is not populated with the synthetic data set.');
        }
        config([
            'database.connections.legacy_django.host' => $cfg['host'], 'database.connections.legacy_django.port' => $cfg['port'],
            'database.connections.legacy_django.database' => self::SCRATCH, 'database.connections.legacy_django.username' => $cfg['username'],
            'database.connections.legacy_django.password' => $cfg['password'],
        ]);
        DB::purge('legacy_django');
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_django');
        parent::tearDown();
    }

    private function run_(array $opts): array
    {
        $path = sys_get_temp_dir().'/portal-import-scratch-'.uniqid().'.json';
        $exit = Artisan::call('portal:import-django', $opts + ['--report' => $path]);
        $r = json_decode((string) @file_get_contents($path), true) ?? [];
        @unlink($path);
        @unlink($path.'.md');

        return [$exit, $r];
    }

    public function test_dirty_django_database_is_blocked_then_imported_with_defaults(): void
    {
        [$exit, $r] = $this->run_([]);
        $this->assertSame(2, $exit);
        $this->assertGreaterThan(20, $r['findings_summary']['blocking']);
        $this->assertSame(0, DB::table('users')->count());

        [$exit, $r] = $this->run_(['--resolve-defaults' => true, '--skip-search-index' => true]);
        $this->assertSame(0, $exit, json_encode($r['errors'] ?? []));
        $this->assertSame($this->legacyCounts['accounts_user'], DB::table('users')->count());
        $this->assertSame($this->legacyCounts['accounts_user'], $r['entities']['users']['imported']);
        $this->assertSame($this->legacyCounts['articles_article'], $r['entities']['articles']['source_count']);
        $this->assertSame([], array_values(array_filter($r['relationship_validation'], fn ($v) => $v['status'] === 'fail')));
        $this->assertNotEmpty($r['unresolved_records']);

        // a migrated PBKDF2 user (Django default 1,000,000 iterations) logs in through the real endpoint
        $this->postJson('/api/auth/login/', ['email' => 'admin@synthetic.test', 'password' => 'Synthetic-Pass-1'])
            ->assertOk()->assertJsonPath('user.role', 'ADMIN');
    }
}
