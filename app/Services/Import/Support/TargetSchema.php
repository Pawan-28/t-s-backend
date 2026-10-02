<?php

namespace App\Services\Import\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Facts about the TARGET (MySQL / MariaDB) database, read from information_schema once per run, and the
 * generic value normalisation that makes every source value fit its target column *before* it is written:
 *
 *  - DATETIME / TIMESTAMP columns hold APP-timezone (config app.timezone) wall-clock values without offset.
 *    Django timestamptz values arrive as instants (e.g. "2026-01-15 10:00:00+00"); they are converted to the
 *    app timezone and formatted 'Y-m-d H:i:s' (MySQL rejects offset-suffixed literals). The instant is preserved.
 *  - DATE columns stay plain 'Y-m-d' dates.
 *  - VARCHAR / TEXT columns: strict mode errors on over-long values, so values are cut to the column limit
 *    (characters for VARCHAR, bytes for TEXT). The PreImportValidator reports every such value beforehand
 *    (rule LEN-TRUNCATED); anything that still gets cut here is counted so the report can flag it.
 *  - Generated columns (publishing_schedules.pending_article_id) are never written.
 */
class TargetSchema
{
    /** Longest text columns whose limit is worth checking (MEDIUMTEXT/LONGTEXT/JSON cannot be exceeded by Django data). */
    private const CHECK_LIMIT_BELOW = 16777215;

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $columns = [];

    private static ?int $packet = null;

    private static ?array $engine = null;

    private static ?\WeakReference $statsExpiryTried = null;

    /** Values cut at write time although the validator had not announced them (must stay 0). */
    public static int $truncatedAtWrite = 0;

    public static function flush(): void
    {
        self::$columns = [];
        self::$packet = null;
        self::$engine = null;
        self::$statsExpiryTried = null;
        self::$truncatedAtWrite = 0;
    }

    /**
     * @return array<string, array{type: string, chars: ?int, bytes: ?int, nullable: bool, has_default: bool, generated: bool, auto: bool, collation: ?string}>
     */
    public static function columns(string $table): array
    {
        $db = DB::connection()->getDatabaseName();
        $key = $db.'.'.$table;
        if (isset(self::$columns[$key])) {
            return self::$columns[$key];
        }
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS maxlen, COLLATION_NAME AS collation, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, EXTRA AS extra
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        );
        $out = [];
        foreach ($rows as $r) {
            $type = strtolower((string) $r->type);
            $extra = strtolower((string) $r->extra);
            $isVarchar = in_array($type, ['varchar', 'char'], true);
            $isText = in_array($type, ['tinytext', 'text', 'mediumtext', 'longtext'], true);
            $out[(string) $r->name] = [
                'type' => $type,
                'chars' => $isVarchar && $r->maxlen !== null ? (int) $r->maxlen : null,
                'bytes' => $isText && $r->maxlen !== null ? (int) $r->maxlen : null,
                'nullable' => strtoupper((string) $r->nullable) === 'YES',
                'has_default' => $r->dflt !== null,
                'generated' => str_contains($extra, 'generated'),
                'auto' => str_contains($extra, 'auto_increment'),
                'collation' => $r->collation !== null ? (string) $r->collation : null,
            ];
        }

        return self::$columns[$key] = $out;
    }

    /** Columns that must be supplied on insert (NOT NULL, no default, not auto-increment, not generated). @return string[] */
    public static function requiredColumns(string $table): array
    {
        $need = [];
        foreach (self::columns($table) as $name => $c) {
            if (! $c['nullable'] && ! $c['has_default'] && ! $c['auto'] && ! $c['generated']) {
                $need[] = $name;
            }
        }

        return $need;
    }

    /** Character/byte limit of a checkable string column: ['chars', N] | ['bytes', N] | null (unbounded / not a string). */
    public static function stringLimit(string $table, string $column): ?array
    {
        $c = self::columns($table)[$column] ?? null;
        if (! $c) {
            return null;
        }
        if ($c['chars'] !== null) {
            return ['chars', $c['chars']];
        }
        if ($c['bytes'] !== null && $c['bytes'] < self::CHECK_LIMIT_BELOW) {
            return ['bytes', $c['bytes']];
        }

        return null;
    }

    public static function maxAllowedPacket(): int
    {
        if (self::$packet === null) {
            try {
                self::$packet = (int) (DB::selectOne('SELECT @@max_allowed_packet AS p')->p ?? 0);
            } catch (\Throwable) {
                self::$packet = 0;
            }
            if (self::$packet <= 0) {
                self::$packet = 4 * 1024 * 1024; // conservative assumption when the server does not tell
            }
        }

        return self::$packet;
    }

    /** Bytes of row data per INSERT statement: a quarter of max_allowed_packet, clamped to 256 KB .. 4 MB (shared hosting: 16 MB packet -> 4 MB). */
    public static function writeBudgetBytes(): int
    {
        return max(256 * 1024, min(4 * 1024 * 1024, intdiv(self::maxAllowedPacket(), 4)));
    }

    /** Placeholder budget per statement (MySQL allows 65535). */
    public const MAX_PLACEHOLDERS = 60000;

    /** @return array{driver: string, engine: string, version: string, version_comment: ?string, sql_mode: ?string, session_time_zone: ?string, max_allowed_packet: int, app_timezone: string} */
    public static function engine(): array
    {
        if (self::$engine) {
            return self::$engine;
        }
        $row = DB::selectOne('SELECT VERSION() AS v, @@version_comment AS c, @@sql_mode AS m, @@session.time_zone AS tz');
        $version = (string) ($row->v ?? '');
        $comment = $row->c ?? null;
        $isMaria = stripos($version.' '.$comment, 'mariadb') !== false;

        return self::$engine = [
            'driver' => DB::connection()->getDriverName(),
            'engine' => $isMaria ? 'MariaDB' : 'MySQL',
            'version' => $version,
            'version_comment' => $comment,
            'sql_mode' => $row->m ?? null,
            'session_time_zone' => $row->tz ?? null,
            'max_allowed_packet' => self::maxAllowedPacket(),
            'app_timezone' => (string) config('app.timezone'),
        ];
    }

    /** Current AUTO_INCREMENT counter of a table (fresh, not the cached statistic MySQL 8 would otherwise serve). */
    public static function autoIncrement(string $table): ?int
    {
        $pdo = DB::connection()->getPdo(); // the session variable lives per connection
        if (self::$statsExpiryTried?->get() !== $pdo) {
            self::$statsExpiryTried = \WeakReference::create($pdo);
            try {
                DB::statement('SET SESSION information_schema_stats_expiry = 0'); // MySQL 8 only; MariaDB has no such variable
            } catch (\Throwable) {
            }
        }
        $row = DB::selectOne('SELECT AUTO_INCREMENT AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);

        if ($row && $row->n !== null) {
            return (int) $row->n;
        }
        // MySQL 8 can report NULL for a just-truncated / not-yet-opened InnoDB table: SHOW TABLE STATUS asks the engine itself.
        $status = DB::selectOne('SHOW TABLE STATUS WHERE Name = ?', [$table]);
        $n = $status ? ($status->Auto_increment ?? null) : null;
        if ($n !== null) {
            return (int) $n;
        }

        return $row ? ((int) (DB::table($table)->max('id') ?? 0)) + 1 : null; // an empty table's counter is 1
    }

    // ------------------------------------------------------------------------------- normalisation

    /** Instant (string with offset / naive string read in UTC / DateTime) -> app-timezone wall clock 'Y-m-d H:i:s'. */
    public static function wallClock(mixed $value): string
    {
        $c = $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC'); // explicit offsets win; naive values are UTC (the legacy session zone)
        $local = $c->setTimezone((string) config('app.timezone'));
        if ($local->year < 1000 || $local->year > 9999) {
            throw new ImportAbort('A date/time value is outside the MySQL DATETIME range (years 1000-9999).', 20);
        }

        return $local->format('Y-m-d H:i:s');
    }

    /**
     * Makes a transformed row fit its target table: datetime/date normalisation, string limits, generated columns dropped.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalizeRow(string $table, array $row): array
    {
        $cols = self::columns($table);
        foreach ($row as $name => $v) {
            $def = $cols[$name] ?? null;
            if ($def === null) {
                continue;
            }
            if ($def['generated']) {
                unset($row[$name]);

                continue;
            }
            if ($v === null) {
                continue;
            }
            switch ($def['type']) {
                case 'datetime':
                case 'timestamp':
                    $row[$name] = self::wallClock($v);
                    break;
                case 'date':
                    $s = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr(trim((string) $v), 0, 10);
                    $row[$name] = $s;
                    break;
                default:
                    if (is_string($v)) {
                        if ($def['chars'] !== null && mb_strlen($v) > $def['chars']) {
                            $row[$name] = mb_substr($v, 0, $def['chars']);
                            self::$truncatedAtWrite++;
                        } elseif ($def['bytes'] !== null && $def['bytes'] < self::CHECK_LIMIT_BELOW && strlen($v) > $def['bytes']) {
                            $row[$name] = mb_strcut($v, 0, $def['bytes'], 'UTF-8');
                            self::$truncatedAtWrite++;
                        }
                    }
            }
        }

        return $row;
    }
}
