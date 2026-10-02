<?php

namespace App\Services\Import;

/**
 * Collects everything the run learned. Contains ids, rule codes and counts only:
 * emails/phones are masked before they are put in, and no secret (password hash,
 * razorpay signature, OTP hash, token) is ever stored here.
 */
class ImportReport
{
    public const SEVERITIES = ['blocking', 'warning', 'info'];

    private array $run = [];

    /** @var array<string, array<string, mixed>> */
    private array $entities = [];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    /** @var list<array<string, mixed>> */
    private array $unresolved = [];

    private array $stats = [];

    private array $sequences = [];

    private array $timings = [];

    private array $notImported = [];

    private array $validation = [];

    private array $errors = [];

    private array $phaseStart = [];

    public function set(string $key, mixed $value): void
    {
        $this->run[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->run[$key] ?? $default;
    }

    // ---- entities -----------------------------------------------------------------
    public function entity(string $name): array
    {
        return $this->entities[$name] ?? ['source_count' => 0, 'imported' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'notes' => []];
    }

    public function setEntity(string $name, array $data): void
    {
        $this->entities[$name] = $data + $this->entity($name);
    }

    public function entityNote(string $name, string $note): void
    {
        $e = $this->entity($name);
        $e['notes'][] = $note;
        $this->entities[$name] = $e;
    }

    public function entities(): array
    {
        return $this->entities;
    }

    // ---- findings -----------------------------------------------------------------
    public function finding(string $entity, int|string|null $id, string $rule, string $severity, string $resolution, string $reason, mixed $old = null, mixed $new = null, bool $resolved = true): void
    {
        $this->findings[] = [
            'entity' => $entity,
            'source_id' => $id,
            'rule' => $rule,
            'severity' => $severity,
            'resolution' => $resolution,
            'reason' => $reason,
            'old' => $old,
            'new' => $new,
            'resolved' => $resolved,
        ];
    }

    public function findings(): array
    {
        return $this->findings;
    }

    public function clearFindings(): void
    {
        $this->findings = [];
    }

    public function count(string $severity): int
    {
        return count(array_filter($this->findings, fn ($f) => $f['severity'] === $severity));
    }

    public function blockingCount(): int
    {
        return $this->count('blocking');
    }

    /** @return list<array{rule: string, entity: string, severity: string, count: int, resolution: string}> */
    public function summaryByRule(): array
    {
        $out = [];
        foreach ($this->findings as $f) {
            $k = $f['rule'].'|'.$f['entity'];
            $out[$k] ??= ['rule' => $f['rule'], 'entity' => $f['entity'], 'severity' => $f['severity'], 'count' => 0, 'resolution' => $f['resolution']];
            $out[$k]['count']++;
        }
        ksort($out);

        return array_values($out);
    }

    // ---- unresolved (skipped) rows ----------------------------------------------------
    public function unresolved(string $entity, int|string|null $id, string $rule, string $reason): void
    {
        $this->unresolved[] = ['entity' => $entity, 'source_id' => $id, 'rule' => $rule, 'reason' => $reason];
        $e = $this->entity($entity);
        $e['skipped']++;
        $this->entities[$entity] = $e;
    }

    public function unresolvedRecords(): array
    {
        return $this->unresolved;
    }

    public function stat(string $key, mixed $value): void
    {
        $this->stats[$key] = $value;
    }

    public function stats(): array
    {
        return $this->stats;
    }

    /** @param int|null $before counter before the reset, $actual counter read back afterwards (information_schema.TABLES.AUTO_INCREMENT) */
    public function sequence(string $table, string $sequence, int $value, ?int $before = null, ?int $actual = null): void
    {
        $this->sequences[] = ['table' => $table, 'sequence' => $sequence, 'set_to' => $value, 'before' => $before, 'actual' => $actual];
    }

    public function notImported(string $item, string $reason, ?int $legacyCount = null): void
    {
        $this->notImported[] = ['item' => $item, 'legacy_count' => $legacyCount, 'reason' => $reason];
    }

    public function validation(string $check, string $status, string $detail): void
    {
        $this->validation[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    public function validationFailures(): int
    {
        return count(array_filter($this->validation, fn ($v) => $v['status'] === 'fail'));
    }

    public function error(string $message, ?string $entity = null): void
    {
        $this->errors[] = ['entity' => $entity, 'message' => $message];
    }

    public function phase(string $name): void
    {
        $this->phaseStart[$name] = microtime(true);
    }

    public function endPhase(string $name): void
    {
        if (isset($this->phaseStart[$name])) {
            $this->timings[$name] = round(microtime(true) - $this->phaseStart[$name], 3);
        }
    }

    // ---- output ---------------------------------------------------------------------
    public function toArray(): array
    {
        $totals = ['source' => 0, 'imported' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($this->entities as $e) {
            $totals['source'] += $e['source_count'];
            $totals['imported'] += $e['imported'];
            $totals['inserted'] += $e['inserted'];
            $totals['updated'] += $e['updated'];
            $totals['skipped'] += $e['skipped'];
        }

        return [
            'run' => $this->run,
            'totals' => $totals,
            'entities' => $this->entities,
            'findings_summary' => [
                'blocking' => $this->count('blocking'),
                'warning' => $this->count('warning'),
                'info' => $this->count('info'),
                'by_rule' => $this->summaryByRule(),
            ],
            'findings' => $this->findings,
            'unresolved_records' => $this->unresolved,
            'stats' => $this->stats,
            'sequence_resets' => $this->sequences,
            'relationship_validation' => $this->validation,
            'timings_seconds' => $this->timings,
            'intentionally_not_imported' => $this->notImported,
            'errors' => $this->errors,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    public function toMarkdown(int $maxRowsPerSection = 300): string
    {
        $a = $this->toArray();
        $r = $a['run'];
        $o = [];
        $o[] = '# Django to Laravel import report';
        $o[] = '';
        $o[] = '- Status: **'.($r['status'] ?? 'unknown').'**';
        $o[] = '- Mode: `'.($r['mode'] ?? '?').'`';
        $o[] = '- Started: '.($r['started_at'] ?? '?').'  Finished: '.($r['finished_at'] ?? '?').'  Duration: '.($r['duration_seconds'] ?? '?').'s';
        $src = $r['source'] ?? [];
        $tgt = $r['target'] ?? [];
        $o[] = '- Source (Django, read-only): `'.($src['database'] ?? '?').'`'.(isset($src['engine']) ? ' ('.$src['engine'].' '.($src['version'] ?? '').')' : '');
        $o[] = '- Target (Laravel): `'.($tgt['database'] ?? '?').'`'.(isset($tgt['engine']) ? ' ('.$tgt['engine'].' '.($tgt['version'] ?? '').', session time zone '.($tgt['session_time_zone'] ?? '?').', app time zone '.($tgt['app_timezone'] ?? '?').', max_allowed_packet '.($tgt['max_allowed_packet'] ?? '?').')' : '');
        $o[] = '- Legacy session read-only verified (server side): '.(($r['read_only_verified'] ?? false) ? 'yes' : 'no');
        $o[] = '- Options: `'.json_encode($r['options'] ?? []).'`';
        $o[] = '';
        $o[] = '## Entities';
        $o[] = '';
        $o[] = '| Entity | Source | Imported | Inserted | Updated | Skipped | Notes |';
        $o[] = '|---|---:|---:|---:|---:|---:|---|';
        foreach ($a['entities'] as $n => $e) {
            $o[] = sprintf('| %s | %d | %d | %d | %d | %d | %s |', $n, $e['source_count'], $e['imported'], $e['inserted'], $e['updated'], $e['skipped'], str_replace('|', '/', implode('; ', $e['notes'])));
        }
        $t = $a['totals'];
        $o[] = sprintf('| **total** | %d | %d | %d | %d | %d | |', $t['source'], $t['imported'], $t['inserted'], $t['updated'], $t['skipped']);
        $o[] = '';
        $s = $a['findings_summary'];
        $o[] = '## Pre-import validation findings';
        $o[] = '';
        $o[] = "Blocking: **{$s['blocking']}**, warnings: **{$s['warning']}**, info: {$s['info']}.";
        $o[] = 'Blocking findings stop the import unless `--resolve-defaults` is passed; the resolution column is what was (or would be) applied.';
        $o[] = '';
        $o[] = '| Rule | Entity | Severity | Count | Resolution |';
        $o[] = '|---|---|---|---:|---|';
        foreach ($s['by_rule'] as $x) {
            $o[] = sprintf('| %s | %s | %s | %d | %s |', $x['rule'], $x['entity'], $x['severity'], $x['count'], str_replace('|', '/', $x['resolution']));
        }
        $o[] = '';
        $o[] = '### Findings detail (entity, source id, rule, severity, resolution, reason)';
        $o[] = '';
        $o[] = '| Entity | Source id | Rule | Severity | Resolution | Reason |';
        $o[] = '|---|---|---|---|---|---|';
        $i = 0;
        foreach ($a['findings'] as $f) {
            if (++$i > $maxRowsPerSection) {
                $o[] = '| ... | | | | | '.(count($a['findings']) - $maxRowsPerSection).' more rows in the JSON report |';
                break;
            }
            $res = $f['resolution'].(($f['old'] !== null || $f['new'] !== null) ? ' ('.json_encode($f['old']).' -> '.json_encode($f['new']).')' : '');
            $o[] = sprintf('| %s | %s | %s | %s | %s | %s |', $f['entity'], $f['source_id'], $f['rule'], $f['severity'], str_replace('|', '/', $res), str_replace('|', '/', $f['reason']));
        }
        $o[] = '';
        $o[] = '## Unresolved / skipped records';
        $o[] = '';
        if (! $a['unresolved_records']) {
            $o[] = 'None. Every source row was imported.';
        } else {
            $o[] = '| Entity | Source id | Rule | Reason |';
            $o[] = '|---|---|---|---|';
            $i = 0;
            foreach ($a['unresolved_records'] as $u) {
                if (++$i > $maxRowsPerSection) {
                    $o[] = '| ... | | | '.(count($a['unresolved_records']) - $maxRowsPerSection).' more rows in the JSON report |';
                    break;
                }
                $o[] = sprintf('| %s | %s | %s | %s |', $u['entity'], $u['source_id'], $u['rule'], str_replace('|', '/', $u['reason']));
            }
        }
        $o[] = '';
        $o[] = '## Relationship validation (after import)';
        $o[] = '';
        if (! $a['relationship_validation']) {
            $o[] = 'Not run (dry-run / blocked / failed).';
        } else {
            $o[] = '| Check | Result | Detail |';
            $o[] = '|---|---|---|';
            foreach ($a['relationship_validation'] as $v) {
                $o[] = sprintf('| %s | %s | %s |', $v['check'], strtoupper($v['status']), str_replace('|', '/', $v['detail']));
            }
        }
        $o[] = '';
        $o[] = '## Sequence resets (AUTO_INCREMENT)';
        $o[] = '';
        if (! $a['sequence_resets']) {
            $o[] = 'None (dry-run / rehearsal).';
        } else {
            $o[] = '| Table | Counter | Before | Set to | Read back |';
            $o[] = '|---|---|---:|---:|---:|';
            foreach ($a['sequence_resets'] as $q) {
                $o[] = sprintf('| %s | %s | %s | %d | %s |', $q['table'], $q['sequence'], $q['before'] ?? '-', $q['set_to'], $q['actual'] ?? '-');
            }
        }
        $o[] = '';
        $o[] = '## Statistics';
        $o[] = '';
        foreach ($a['stats'] as $k => $v) {
            $o[] = '- '.$k.': `'.(is_scalar($v) ? $v : json_encode($v)).'`';
        }
        $o[] = '';
        $o[] = '## Intentionally not imported';
        $o[] = '';
        $o[] = '| Item | Legacy rows | Reason |';
        $o[] = '|---|---:|---|';
        foreach ($a['intentionally_not_imported'] as $n) {
            $o[] = sprintf('| %s | %s | %s |', $n['item'], $n['legacy_count'] ?? '-', str_replace('|', '/', $n['reason']));
        }
        $o[] = '';
        $o[] = '## Timings (seconds)';
        $o[] = '';
        foreach ($a['timings_seconds'] as $k => $v) {
            $o[] = "- {$k}: {$v}";
        }
        if ($a['errors']) {
            $o[] = '';
            $o[] = '## Errors';
            $o[] = '';
            foreach ($a['errors'] as $e) {
                $o[] = '- '.($e['entity'] ? '['.$e['entity'].'] ' : '').$e['message'];
            }
        }
        $o[] = '';

        return implode("\n", $o);
    }

    /** Writes .json / .md by extension; any other path (or a bare name) gets both. Returns written paths. */
    public function write(string $path): array
    {
        $written = [];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $targets = match ($ext) {
            'json' => [$path => 'json'],
            'md', 'markdown' => [$path => 'md'],
            default => [$path.'.json' => 'json', $path.'.md' => 'md'],
        };
        foreach ($targets as $file => $kind) {
            $dir = dirname($file);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($file, $kind === 'json' ? $this->toJson() : $this->toMarkdown());
            $written[] = $file;
        }

        return $written;
    }
}
