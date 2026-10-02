<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\ImportAbort;
use App\Services\Import\Support\TargetSchema;
use Illuminate\Support\Facades\DB;

abstract class AbstractEntityImporter
{
    /** Plain source column names (also used for the schema pre-check). */
    protected const COLUMNS = [];

    /** Optional select expressions per column (e.g. jsonb -> text). */
    protected const EXPRESSIONS = [];

    abstract public function name(): string;

    abstract public function sourceTable(): string;

    /** @return array<string, mixed>|null target row (all rows of one entity must share the same keys), null = skipped */
    abstract public function transform(array $row, ImportContext $ctx): ?array;

    /** Tables (besides sourceTable) that must exist for this entity to be importable. */
    public function extraSourceTables(): array
    {
        return [];
    }

    /** Whether the entity may be absent from an older/partial Django database (then it is reported and skipped). */
    public function optional(): bool
    {
        return false;
    }

    /** @return string[] plain source column names */
    public function columns(): array
    {
        return static::COLUMNS;
    }

    public function targetTable(): string
    {
        return ImportContext::TARGET_TABLES[$this->name()];
    }

    /** @return array<string, string[]> table => required columns */
    public function requiredColumns(): array
    {
        return [$this->sourceTable() => static::COLUMNS];
    }

    public function selectFrom(): string
    {
        $cols = [];
        foreach (static::COLUMNS as $c) {
            $cols[] = isset(static::EXPRESSIONS[$c]) ? static::EXPRESSIONS[$c].' AS "'.$c.'"' : '"'.$c.'"';
        }

        return 'SELECT '.implode(', ', $cols).' FROM "'.$this->sourceTable().'"';
    }

    public function idExpr(): string
    {
        return '"id"';
    }

    /** Non-key columns to overwrite when a row already exists (idempotent re-run). */
    protected function updateColumns(array $row): array
    {
        return array_values(array_diff(array_keys($row), ['id']));
    }

    public function run(ImportContext $ctx): void
    {
        $name = $this->name();
        $report = $ctx->report;
        $ctx->markRan($name);

        if (! $ctx->reader->hasTable($this->sourceTable())) {
            $report->setEntity($name, ['source_count' => 0]);
            $report->entityNote($name, 'source table "'.$this->sourceTable().'" not present in the legacy database - nothing to import');

            return;
        }

        $report->setEntity($name, ['source_count' => $ctx->reader->count($this->sourceTable())]);

        $table = $this->targetTable();
        $checkedRequired = false;

        $work = function () use ($ctx, $name, $report, $table, &$checkedRequired) {
            $ctx->reader->chunked($this->selectFrom(), $this->idExpr(), $ctx->chunk, function (array $rows) use ($ctx, $name, $report, $table, &$checkedRequired) {
                $out = [];
                foreach ($rows as $row) {
                    $t = $this->transform($row, $ctx);
                    if ($t === null) {
                        continue;
                    }
                    // datetime -> app-timezone wall clock, date, string limits, generated columns dropped
                    $t = TargetSchema::normalizeRow($table, $t);
                    $out[] = $t;
                    $ctx->markImported($name, (int) $t['id']);
                }
                if (! $out) {
                    return;
                }
                if (! $checkedRequired) {
                    $checkedRequired = true;
                    $missing = array_diff(TargetSchema::requiredColumns($table), array_keys($out[0]));
                    if ($missing) {
                        // MySQL has no DB defaults for TEXT/JSON: every NOT NULL column must be supplied explicitly.
                        throw new ImportAbort("Importer bug: '{$name}' does not supply NOT NULL column(s) of {$table}: ".implode(', ', $missing), 20, $name);
                    }
                }
                $e = $report->entity($name);
                $e['imported'] += count($out);
                if ($ctx->dryRun) {
                    $e['inserted'] += count($out);
                    $report->setEntity($name, $e);

                    return;
                }
                $existing = 0;
                if (! $ctx->targetEmpty) {
                    foreach (array_chunk(array_column($out, 'id'), 2000) as $idChunk) {
                        $existing += DB::table($table)->whereIn('id', $idChunk)->count();
                    }
                }
                $update = $this->updateColumns($out[0]);
                foreach ($this->batches($out, $name) as $batch) {
                    DB::table($table)->upsert($batch, ['id'], $update); // INSERT ... ON DUPLICATE KEY UPDATE
                }
                $e['updated'] += $existing;
                $e['inserted'] += count($out) - $existing;
                $report->setEntity($name, $e);
            });
        };

        try {
            if ($ctx->dryRun) {
                $work();
            } else {
                // One transaction per entity: a failure rolls back the whole entity.
                DB::transaction($work);
            }
        } catch (ImportAbort $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ImportAbort("Import of '{$name}' failed and was rolled back: ".ImportAbort::sanitize($e), 20, $name, $e);
        }
    }

    /**
     * Splits rows into INSERT statements that respect MySQL limits: < 60000 placeholders (limit 65535) and a byte budget
     * derived from max_allowed_packet (LONGTEXT-heavy articles are cut into small statements).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return \Generator<int, list<array<string, mixed>>>
     */
    protected function batches(array $rows, string $entity): \Generator
    {
        $cols = max(1, count($rows[0]));
        $maxRows = max(1, intdiv(TargetSchema::MAX_PLACEHOLDERS - 1, $cols));
        $budget = TargetSchema::writeBudgetBytes();
        $packet = TargetSchema::maxAllowedPacket();
        $batch = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $size = 32 * $cols;
            foreach ($row as $v) {
                if (is_string($v)) {
                    $size += strlen($v);
                }
            }
            if ($size * 1.1 > $packet) {
                throw new ImportAbort("A row of '{$entity}' (id ".($row['id'] ?? '?').") is larger than the MySQL max_allowed_packet ({$packet} bytes). Raise max_allowed_packet (hPanel / my.cnf) and re-run.", 20, $entity);
            }
            if ($batch && (count($batch) >= $maxRows || $bytes + $size > $budget)) {
                yield $batch;
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $row;
            $bytes += $size;
        }
        if ($batch) {
            yield $batch;
        }
    }

    /** Hook for rows that live outside the main scan (reported, never silently dropped). */
    public function afterRun(ImportContext $ctx): void {}

    // ---- helpers ------------------------------------------------------------------
    protected static function str(mixed $v): string
    {
        return $v === null ? '' : (string) $v;
    }

    /**
     * A value for a JSON column: always a valid JSON document (arrays/objects are encoded; text is kept verbatim when it is
     * valid JSON - PostgreSQL's jsonb::text always is; anything else becomes $default). Never returns invalid JSON.
     */
    protected static function json(mixed $v, string $default = '[]'): string
    {
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: $default;
        }
        $s = trim((string) $v);

        return $s !== '' && json_validate($s) ? $s : $default;
    }

    protected static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }
}
