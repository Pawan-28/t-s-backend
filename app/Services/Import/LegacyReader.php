<?php

namespace App\Services\Import;

use App\Services\Import\Support\ImportAbort;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY window onto the Django (legacy) PostgreSQL database.
 *
 * Guarantees:
 *  - the session is put in `default_transaction_read_only = on` and the whole
 *    read runs inside ONE `REPEATABLE READ, READ ONLY` transaction (consistent
 *    snapshot; the server itself rejects every write);
 *  - the public API only exposes SELECT-style helpers; SQL that is not a plain
 *    SELECT/WITH/SHOW (or that contains a write/DDL keyword) is refused before
 *    it reaches the driver;
 *  - it never commits, only rolls back on close().
 */
class LegacyReader
{
    public const CONNECTION = 'legacy_django';

    private bool $opened = false;

    private ?string $identityCache = null;

    public function __construct(private string $connectionName = self::CONNECTION) {}

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    private function db(): Connection
    {
        return DB::connection($this->connectionName);
    }

    public function open(): void
    {
        if ($this->opened) {
            return;
        }
        $cfg = config('database.connections.'.$this->connectionName);
        if (! $cfg || empty($cfg['database'])) {
            throw new ImportAbort('Legacy connection is not configured: set LEGACY_DB_HOST, LEGACY_DB_PORT, LEGACY_DB_DATABASE, LEGACY_DB_USERNAME and LEGACY_DB_PASSWORD.', 10);
        }
        try {
            $pdo = $this->db()->getPdo();
            $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
            $pdo->exec("SET TIME ZONE 'UTC'");
            $pdo->exec('SET statement_timeout = 0');
            $this->db()->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        } catch (\Throwable $e) {
            throw new ImportAbort('Cannot open the legacy database read-only: '.ImportAbort::sanitize($e), 10, null, $e);
        }
        $this->opened = true;
    }

    /** Proves (server side) that this session cannot write. Returns true or throws. */
    public function assertReadOnly(): bool
    {
        $this->open();
        $flag = $this->db()->selectOne('SHOW transaction_read_only')->transaction_read_only ?? null;
        if ($flag !== 'on') {
            throw new ImportAbort('Refusing to continue: the legacy session is not read-only.', 11);
        }
        $pdo = $this->db()->getPdo();
        $pdo->exec('SAVEPOINT ro_probe');
        $rejected = false;
        try {
            $pdo->exec('CREATE TEMP TABLE __import_write_probe (x int)');
        } catch (\Throwable) {
            $rejected = true;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ro_probe');
        if (! $rejected) {
            throw new ImportAbort('Refusing to continue: the legacy database accepted a write probe.', 11);
        }

        return true;
    }

    public function close(): void
    {
        if (! $this->opened) {
            return;
        }
        try {
            while ($this->db()->transactionLevel() > 0) {
                $this->db()->rollBack();
            }
        } catch (\Throwable) {
        }
        DB::purge($this->connectionName);
        $this->opened = false;
    }

    /** Identity used by the same-database guard (server-reported, alias independent). */
    public function identity(): array
    {
        $this->open();
        $row = $this->db()->selectOne(
            'SELECT current_database() AS db, host(inet_server_addr()) AS addr, inet_server_port() AS port,
                    (SELECT oid::bigint FROM pg_database WHERE datname = current_database()) AS oid'
        );

        return ['database' => $row->db, 'addr' => $row->addr, 'port' => $row->port, 'oid' => $row->oid];
    }

    private function assertSelect(string $sql): void
    {
        $bare = preg_replace("/'(?:[^']|'')*'/", "''", $sql) ?? $sql;
        if (! preg_match('/^\s*(select|with|show)\b/i', $bare)) {
            throw new ImportAbort('LegacyReader only executes SELECT statements.', 11);
        }
        if (preg_match('/\b(insert|update|delete|drop|alter|create|truncate|grant|revoke|copy|vacuum|call|do|lock|set|reset|nextval|setval)\b/i', $bare)) {
            throw new ImportAbort('LegacyReader refused a statement containing a write/DDL keyword.', 11);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        $this->open();
        $this->assertSelect($sql);
        $rows = $this->db()->select($sql, $bindings);

        return array_map(fn ($r) => (array) $r, $rows);
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $rows = $this->select($sql, $bindings);
        if (! $rows) {
            return null;
        }

        return array_values($rows[0])[0] ?? null;
    }

    public function hasTable(string $table): bool
    {
        return (bool) $this->scalar('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?', [$table]);
    }

    /** @return string[] */
    public function columns(string $table): array
    {
        return array_column($this->select('SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?', [$table]), 'column_name');
    }

    public function count(string $table): int
    {
        // table names come from internal constants only
        return (int) $this->scalar('SELECT count(*) FROM "'.str_replace('"', '', $table).'"');
    }

    /**
     * Keyset-paginated streaming read. $selectFrom = "SELECT ... FROM t [JOIN ...]" (no WHERE / ORDER BY).
     * The callback receives array<int, array<string,mixed>> per chunk.
     */
    public function chunked(string $selectFrom, string $idExpr, int $size, callable $callback): void
    {
        $last = 0;
        while (true) {
            $rows = $this->select($selectFrom.' WHERE '.$idExpr.' > ? ORDER BY '.$idExpr.' ASC LIMIT '.max(1, $size), [$last]);
            if (! $rows) {
                return;
            }
            $callback($rows);
            $lastRow = end($rows);
            $last = (int) ($lastRow['__id'] ?? $lastRow['id']);
            if (count($rows) < $size) {
                return;
            }
        }
    }
}
