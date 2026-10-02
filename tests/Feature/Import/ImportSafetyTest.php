<?php

namespace Tests\Feature\Import;

use App\Services\Import\LegacyReader;
use App\Services\Import\Support\DatabaseIdentity;
use App\Services\Import\Support\ImportAbort;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyFixture;

class ImportSafetyTest extends ImportTestCase
{
    public function test_legacy_connection_is_read_only_at_the_server(): void
    {
        $ids = $this->legacy->baseline();
        $reader = new LegacyReader;

        $this->assertTrue($reader->assertReadOnly());
        $conn = DB::connection('legacy_django');

        foreach ([
            fn () => $conn->statement('CREATE TABLE portal_import_probe (a int)'),
            fn () => $conn->update("UPDATE accounts_user SET role = 'ADMIN'"),
            fn () => $conn->delete('DELETE FROM accounts_user'),
            fn () => $conn->insert("INSERT INTO categories_tag (name, slug, created_at) VALUES ('x', 'x', now())"),
            fn () => $conn->statement('TRUNCATE accounts_user CASCADE'),
        ] as $write) {
            $conn->getPdo()->exec('SAVEPOINT probe');
            try {
                $write();
                $this->fail('the legacy connection accepted a write');
            } catch (QueryException $e) {
                $this->assertStringContainsString('read-only', strtolower($e->getPrevious()->getMessage()));
            }
            $conn->getPdo()->exec('ROLLBACK TO SAVEPOINT probe');
        }
        $reader->close();
        $this->assertSame(3, (int) $this->legacy->query('SELECT count(*) AS n FROM accounts_user')[0]['n']);
    }

    public function test_reader_refuses_anything_but_select(): void
    {
        $reader = new LegacyReader;
        $reader->open();
        foreach (['UPDATE accounts_user SET role = 1', 'DELETE FROM accounts_user', 'DROP TABLE accounts_user', 'INSERT INTO categories_tag DEFAULT VALUES', 'WITH x AS (DELETE FROM accounts_user RETURNING id) SELECT * FROM x', 'SELECT 1; DROP TABLE accounts_user'] as $sql) {
            try {
                $reader->select($sql);
                $this->fail("reader executed: {$sql}");
            } catch (ImportAbort $e) {
                $this->assertSame(11, $e->exitCode);
            }
        }
        $this->assertSame(0, $reader->count('accounts_user'));
        $reader->close();
    }

    public function test_full_import_leaves_the_legacy_database_byte_identical(): void
    {
        $this->legacy->baseline();
        $this->legacy->user(['email' => 'A@example.test']);
        $this->legacy->user(['email' => 'a@example.test']);
        $digest = fn () => $this->legacy->query(
            "SELECT (SELECT md5(string_agg(u::text, '|' ORDER BY id)) FROM accounts_user u) AS u,
                    (SELECT md5(string_agg(a::text, '|' ORDER BY id)) FROM articles_article a) AS a,
                    (SELECT md5(string_agg(p::text, '|' ORDER BY id)) FROM subscriptions_payment p) AS p,
                    (SELECT sum(n_tup_ins + n_tup_upd + n_tup_del) FROM pg_stat_user_tables) AS writes"
        )[0];
        $before = $digest();

        $this->runImport();                                   // blocked
        $this->runImport(['--resolve-defaults' => true]);     // full import
        $this->runImport(['--resolve-defaults' => true, '--allow-non-empty' => true]);

        $after = $digest();
        $this->assertSame($before['u'], $after['u']);
        $this->assertSame($before['a'], $after['a']);
        $this->assertSame($before['p'], $after['p']);
        $this->assertGreaterThan(0, $this->rows('users'));
    }

    public function test_import_refuses_when_the_target_connection_is_the_legacy_connection(): void
    {
        $this->legacy->baseline();
        config(['database.default' => 'legacy_django']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(12, $exit);
        $this->assertSame('refused', $r['run']['status']);
        $this->assertStringContainsString('legacy connection', $r['errors'][0]['message']);
    }

    public function test_the_target_must_be_mysql_or_mariadb_and_the_legacy_source_must_be_postgresql(): void
    {
        $this->legacy->baseline();

        config(['database.default' => 'pgsql']);
        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(12, $exit);
        $this->assertStringContainsString('MySQL or MariaDB', $r['errors'][0]['message']);
        config(['database.default' => 'mysql']);

        config(['database.connections.legacy_django.driver' => 'mysql']);
        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(10, $exit);
        $this->assertStringContainsString('must be PostgreSQL', $r['errors'][0]['message']);
        $this->assertSame(0, $this->rows('users'));
    }

    public function test_same_database_guard_compares_the_driver_first_then_the_identity(): void
    {
        $pg = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'portal'];
        // different engines can never be the same database, even with identical host/port/name strings
        $this->assertFalse(DatabaseIdentity::same($pg, ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'portal']));
        $this->assertFalse(DatabaseIdentity::same($pg, ['driver' => 'mariadb', 'host' => 'localhost', 'port' => '5432', 'database' => 'portal']));
        // same engine: identity decides, host aliases are normalised
        $this->assertTrue(DatabaseIdentity::same($pg, ['driver' => 'pgsql', 'host' => 'localhost', 'port' => 5432, 'database' => 'portal']));
        $this->assertFalse(DatabaseIdentity::same($pg, ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'other']));
        $this->assertFalse(DatabaseIdentity::same($pg, ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => '5432', 'database' => 'portal']));
        $my = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'portal'];
        $this->assertTrue(DatabaseIdentity::same($my, ['driver' => 'mariadb', 'host' => 'localhost', 'port' => '3306', 'database' => 'portal']));
    }

    public function test_a_non_django_database_or_missing_configuration_is_refused(): void
    {
        config(['database.connections.legacy_django.database' => '']);
        DB::purge('legacy_django');
        [$exit] = $this->runImport();
        $this->assertSame(10, $exit);

        // legacy pointing at a PostgreSQL database that is not the Django one is refused too
        $pg = LegacyFixture::settings();
        config(['database.connections.legacy_django.database' => 'postgres', 'database.connections.legacy_django.host' => $pg['host'], 'database.connections.legacy_django.port' => $pg['port'],
            'database.connections.legacy_django.username' => $pg['username'], 'database.connections.legacy_django.password' => $pg['password']]);
        DB::purge('legacy_django');
        [$exit, $r] = $this->runImport();
        $this->assertSame(10, $exit);
        $this->assertStringContainsString('does not look like the Django', $r['errors'][0]['message']);
        $this->assertSame(0, $this->rows('users'));
    }

    public function test_the_read_only_probe_and_engines_are_recorded_in_the_report(): void
    {
        $this->legacy->baseline();

        [$exit, $r] = $this->runImport(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertTrue($r['run']['read_only_verified']);
        $this->assertSame('PostgreSQL', $r['run']['source']['engine']);
        $this->assertTrue($r['run']['source']['read_only']);
        $this->assertContains($r['run']['target']['engine'], ['MariaDB', 'MySQL']);
        $this->assertSame(DB::connection()->getDatabaseName(), $r['run']['target']['database']);
        $this->assertNotEmpty($r['run']['target']['version']);
        $this->assertSame(config('app.timezone'), $r['run']['target']['app_timezone']);
        $this->assertGreaterThan(0, $r['run']['target']['max_allowed_packet']);
    }
}
