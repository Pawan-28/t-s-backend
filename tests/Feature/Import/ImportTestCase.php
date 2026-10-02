<?php

namespace Tests\Feature\Import;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyFixture;
use Tests\TestCase;

/**
 * Base for the Django importer tests. The legacy side is a PostgreSQL database (`<DB_DATABASE>_legacy`, see LegacyFixture::settings()) that
 * holds the real Django schema; the target is the MySQL/MariaDB test database. Tests are skipped when PostgreSQL is unavailable.
 */
abstract class ImportTestCase extends TestCase
{
    // Not RefreshDatabase: the importer's AUTO_INCREMENT reset is DDL (implicit commit), which would end the wrapping test
    // transaction. Committed data + truncation between tests mirrors production exactly.
    use DatabaseTruncation;

    protected LegacyFixture $legacy;

    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $fixture = LegacyFixture::boot();
        if (! $fixture) {
            $this->markTestSkipped('PostgreSQL legacy fixture database is unavailable (server down or CREATE DATABASE not permitted).');
        }
        $this->legacy = $fixture;
        $this->legacy->attach();
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_django');
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} [exit code, decoded JSON report, console output] */
    protected function runImport(array $options = []): array
    {
        $path = sys_get_temp_dir().'/portal-import-'.uniqid('', true).'.json';
        $this->tmpFiles[] = $path;
        $this->tmpFiles[] = $path.'.md';
        $exit = Artisan::call('portal:import-django', $options + ['--report' => $path]);
        $out = Artisan::output();
        $report = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return [$exit, $report ?? [], $out];
    }

    protected function findings(array $report, ?string $rule = null, ?string $entity = null): array
    {
        return array_values(array_filter($report['findings'] ?? [], fn ($f) => ($rule === null || $f['rule'] === $rule) && ($entity === null || $f['entity'] === $entity)));
    }

    protected function rules(array $report): array
    {
        return array_values(array_unique(array_column($report['findings'] ?? [], 'rule')));
    }

    protected function rows(string $table): int
    {
        return DB::table($table)->count();
    }
}
