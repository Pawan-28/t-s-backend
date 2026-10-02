<?php

namespace App\Console\Commands;

use App\Services\Import\Importer;
use App\Services\Import\ImportReport;
use Illuminate\Console\Command;

class ImportDjango extends Command
{
    protected $signature = 'portal:import-django
        {--dry-run : Validate and plan only; nothing is written to the target}
        {--rehearse : Run the full import inside one transaction that is rolled back at the end (proves every constraint, writes nothing)}
        {--report= : Write the import report to this path (.json or .md; any other value writes both <path>.json and <path>.md)}
        {--only= : Comma separated entities to import (default: all, in dependency order)}
        {--chunk=500 : Rows per read/write chunk}
        {--allow-non-empty : Permit a non-empty target; rows are upserted idempotently by source id (overwrites rows with the same id)}
        {--resolve-defaults : Accept the deterministic default resolutions for BLOCKING pre-import findings}
        {--skip-search-index : Do not rebuild the article_search_index (FULLTEXT) table after the import; run articles:reindex-search later}';

    protected $description = 'Read-only Django -> Laravel data import with pre-import validation and a JSON/Markdown report (LEGACY_DB_* = Django PostgreSQL database, read-only; DB_* = new MySQL/MariaDB database).';

    public function handle(): int
    {
        $report = new ImportReport;
        $importer = new Importer(null, $report, fn (string $m) => $this->line($m));

        $exit = $importer->run([
            'dry_run' => (bool) $this->option('dry-run'),
            'rehearse' => (bool) $this->option('rehearse'),
            'only' => $this->option('only') ? explode(',', (string) $this->option('only')) : [],
            'chunk' => (int) $this->option('chunk'),
            'allow_non_empty' => (bool) $this->option('allow-non-empty'),
            'resolve_defaults' => (bool) $this->option('resolve-defaults'),
            'skip_search_index' => (bool) $this->option('skip-search-index'),
        ]);

        if ($path = $this->option('report')) {
            foreach ($report->write((string) $path) as $file) {
                $this->info("Report written: {$file}");
            }
        }

        $t = $report->toArray();
        $this->newLine();
        if ($t['entities']) {
            $this->table(['entity', 'source', 'imported', 'inserted', 'updated', 'skipped'], array_map(
                fn ($n, $e) => [$n, $e['source_count'], $e['imported'], $e['inserted'], $e['updated'], $e['skipped']],
                array_keys($t['entities']),
                $t['entities']
            ));
        }
        foreach ($t['relationship_validation'] as $v) {
            if ($v['status'] !== 'pass') {
                $this->line(strtoupper($v['status']).' '.$v['check'].': '.$v['detail']);
            }
        }
        $line = 'Status: '.$t['run']['status'].' (exit '.$exit.')';
        $exit === 0 ? $this->info($line) : $this->error($line);

        return $exit;
    }
}
