<?php

namespace Tests\Feature\Import;

use App\Services\Import\Support\Masker;
use App\Services\Import\Support\SlugPlanner;
use Illuminate\Support\Facades\Artisan;

class ImportReportTest extends ImportTestCase
{
    public function test_report_has_all_required_sections_and_no_pii_or_secrets(): void
    {
        $ids = $this->legacy->baseline();
        $this->legacy->user(['email' => 'Alice.Smith@Example.test', 'phone' => '+919111111111']);
        $this->legacy->user(['email' => 'alice.smith@example.test']);
        $this->legacy->article(['author_id' => 4040]);       // orphan author -> unresolved

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit);

        foreach (['run', 'totals', 'entities', 'findings_summary', 'findings', 'unresolved_records', 'stats', 'sequence_resets', 'relationship_validation', 'timings_seconds', 'intentionally_not_imported', 'errors'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame('completed', $r['run']['status']);
        $this->assertTrue($r['run']['read_only_verified']);
        $this->assertSame('import', $r['run']['mode']);
        foreach (['users', 'articles', 'payments', 'ai_analyses'] as $e) {
            $this->assertArrayHasKey('source_count', $r['entities'][$e]);
            $this->assertArrayHasKey('imported', $r['entities'][$e]);
            $this->assertArrayHasKey('skipped', $r['entities'][$e]);
            $this->assertArrayHasKey('updated', $r['entities'][$e]);
        }
        $f = $this->findings($r, 'USR-EMAIL-CASE-DUP')[0];
        foreach (['entity', 'source_id', 'rule', 'severity', 'resolution', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $f);
        }
        $this->assertSame(Masker::email('alice.smith@example.test'), $f['old']);
        $this->assertSame('a***@e***.test', $f['old']);
        $this->assertNotEmpty($r['unresolved_records']);
        $this->assertNotEmpty($r['sequence_resets']);
        $this->assertArrayHasKey('import.users', $r['timings_seconds']);
        $items = array_column($r['intentionally_not_imported'], null, 'item');
        foreach (['auth_group', 'accounts_user_user_permissions', 'token_blacklist_outstandingtoken', 'subscriptions_phoneotp'] as $item) {
            $this->assertArrayHasKey($item, $items);
            $this->assertNotEmpty($items[$item]['reason']);
        }

        $blob = json_encode($r).$out;
        foreach (['admin@example.test', 'rep@example.test', 'reader@example.test', 'alice.smith', 'Alice.Smith', '+919111111111', 'sig-fixture-secret-value', 'pbkdf2_sha256$', '"secret":"raw"'] as $needle) {
            $this->assertStringNotContainsString($needle, $blob, "report/console leaks {$needle}");
        }
    }

    public function test_markdown_and_json_reports_are_written(): void
    {
        $this->legacy->baseline();
        $base = sys_get_temp_dir().'/portal-import-md-'.uniqid();

        $exit = Artisan::call('portal:import-django', ['--report' => $base]);
        $this->assertSame(0, $exit);
        $this->assertFileExists($base.'.json');
        $this->assertFileExists($base.'.md');
        $md = file_get_contents($base.'.md');
        foreach (['# Django to Laravel import report', '## Entities', '## Pre-import validation findings', '## Unresolved / skipped records', '## Relationship validation', '## Sequence resets', '## Intentionally not imported', '## Timings'] as $h) {
            $this->assertStringContainsString($h, $md);
        }
        $this->assertNotNull(json_decode(file_get_contents($base.'.json'), true));
        @unlink($base.'.json');
        @unlink($base.'.md');
    }

    public function test_masker(): void
    {
        $this->assertSame('a***@e***.com', Masker::email('alice@example.com'));
        $this->assertSame('(blank)', Masker::email(''));
        $this->assertSame('***10', Masker::phone('+91 98765 43210'));
    }

    public function test_slug_planner_is_deterministic(): void
    {
        $rows = [1 => ['slug' => 'A', 'name' => 'x'], 2 => ['slug' => 'a', 'name' => 'x'], 3 => ['slug' => '', 'name' => 'Hello World'], 4 => ['slug' => 'hello-world', 'name' => 'y']];
        $a = SlugPlanner::plan($rows, 50);
        $b = SlugPlanner::plan(array_reverse($rows, true), 50);
        $this->assertSame($a, $b);
        $this->assertSame('A', $a[1]['slug']);
        $this->assertSame('a-2', $a[2]['slug']);
        $this->assertSame('hello-world', $a[4]['slug']);
        $this->assertSame('hello-world-2', $a[3]['slug']);
        $this->assertTrue($a[3]['generated']);
    }
}
