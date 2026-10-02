<?php

namespace App\Services\Import;

use App\Services\Import\Support\Collation;
use App\Services\Import\Support\Masker;
use App\Services\Import\Support\Rules;
use App\Services\Import\Support\SlugPlanner;
use App\Services\Import\Support\TargetSchema;
use Illuminate\Support\Facades\DB;

/**
 * Reads the legacy DB (read-only, SQL anti-joins / aggregates) and reports every
 * data problem BEFORE anything is written. Produces findings (entity, source id,
 * rule, severity, resolution, reason) and the global ResolutionPlan applied by the
 * importers. Nothing is silently discarded: every dropped or altered row appears
 * as a finding (and skipped rows again in the report's unresolved list).
 *
 * Severity:
 *   blocking - the row would be skipped, or an identifier/value would be replaced
 *              (email alias, slug suffix, phone cleared, enum defaulted ...).
 *              The import refuses to run until --resolve-defaults accepts these
 *              deterministic default resolutions.
 *   warning  - imported with a lossless normalisation, or kept as-is but flagged.
 *   info     - informational.
 */
class PreImportValidator
{
    public const RULES = [
        // users
        'USR-EMAIL-BLANK' => 'blocking', 'USR-EMAIL-CASE-DUP' => 'blocking', 'USR-EMAIL-TARGET-CONFLICT' => 'blocking',
        'USR-EMAIL-INVALID' => 'warning', 'USR-EMAIL-NORMALIZED' => 'info',
        'USR-PHONE-BLANK' => 'warning', 'USR-PHONE-MALFORMED' => 'warning', 'USR-PHONE-NORMALIZED' => 'info',
        'USR-PHONE-TOOLONG' => 'blocking', 'USR-PHONE-DUP' => 'blocking', 'USR-PHONE-VERIFIED-NO-PHONE' => 'warning',
        'LEN-TRUNCATED' => 'blocking', 'IND-NAME-DUP' => 'blocking', 'CAT-NAME-DUP' => 'blocking', 'TAG-NAME-CASE-DUP' => 'blocking', 'PAY-ORDERID-DUP' => 'blocking', 'PLG-SCANID-DUP' => 'blocking',
        'USR-ROLE-INVALID' => 'blocking', 'USR-SUPERUSER-ROLE' => 'warning', 'USR-PASSWORD-UNSUPPORTED' => 'warning',
    ];

    private array $plan;

    private ResolutionPlan $p;

    /** @var array<string, array<int, true>> entity => ids that will be skipped */
    private array $skipped = [];

    private array $selected = [];

    private bool $targetChecks = false;

    public function __construct(private LegacyReader $r, private ImportReport $report) {}

    /**
     * @param  string[]  $entities  selected entity names
     * @param  bool  $targetChecks  also check collisions with rows already in the target (non-empty target runs)
     */
    public function run(array $entities, bool $targetChecks = false): ResolutionPlan
    {
        $this->p = new ResolutionPlan;
        $this->selected = array_fill_keys($entities, true);
        $this->targetChecks = $targetChecks;

        foreach (['users', 'industries', 'categories', 'subcategories', 'tags', 'subscription_plans', 'articles', 'article_tags', 'article_images', 'article_reviews', 'publishing_schedules', 'reporter_assignments', 'subscriptions', 'payments', 'notifications', 'advertisements', 'article_daily_views', 'ai_analyses', 'plagiarism_checks'] as $e) {
            if (! isset($this->selected[$e])) {
                continue;
            }
            $m = 'check'.str_replace('_', '', ucwords($e, '_'));
            $this->$m();
            $this->checkLengths($e);
        }

        return $this->p;
    }

    // ---------------------------------------------------------------- helpers
    private function f(string $entity, int|string|null $id, string $rule, string $sev, string $resolution, string $reason, mixed $old = null, mixed $new = null): void
    {
        $this->report->finding($entity, $id, $rule, $sev, $resolution, $reason, $old, $new);
    }

    private function has(string $table): bool
    {
        return $this->r->hasTable($table);
    }

    private function inList(array $values): string
    {
        return "('".implode("','", array_map(fn ($v) => str_replace("'", "''", $v), $values))."')";
    }

    /**
     * Generic FK rule. mode: skip (row cannot be imported) | null (dangling optional ref set to NULL).
     * Rows whose parent was itself skipped are reported with the cascade reason.
     */
    private function fkRule(string $entity, string $table, string $col, string $parentEntity, string $parentTable, string $mode, string $rule, string $resolution, ?string $extraWhere = null): void
    {
        if (! $this->has($table)) {
            return;
        }
        $sev = $mode === 'skip' ? 'blocking' : 'warning';
        $where = $extraWhere ? " AND ({$extraWhere})" : '';
        $rows = $this->r->select("SELECT c.id, c.\"{$col}\" AS fk FROM \"{$table}\" c WHERE c.\"{$col}\" IS NOT NULL{$where} AND NOT EXISTS (SELECT 1 FROM \"{$parentTable}\" p WHERE p.id = c.\"{$col}\") ORDER BY c.id");
        foreach ($rows as $row) {
            $this->f($entity, (int) $row['id'], $rule, $sev, $resolution, "{$col} {$row['fk']} references a missing {$parentTable} row");
            if ($mode === 'skip') {
                $this->skipped[$entity][(int) $row['id']] = true;
            }
        }
        $gone = array_keys($this->skipped[$parentEntity] ?? []);
        foreach (array_chunk($gone, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->r->select("SELECT c.id, c.\"{$col}\" AS fk FROM \"{$table}\" c WHERE c.\"{$col}\" IN ({$ph}){$where} ORDER BY c.id", $chunk);
            foreach ($rows as $row) {
                $this->f($entity, (int) $row['id'], $rule, $sev, $resolution, "{$col} {$row['fk']} references a {$parentEntity} row that is itself skipped");
                if ($mode === 'skip') {
                    $this->skipped[$entity][(int) $row['id']] = true;
                }
            }
        }
    }

    /** Enum-like column check. */
    private function enumRule(string $entity, string $table, string $col, array $allowed, string $rule, string $sev, string $resolution, bool $skip = false, ?string $default = null): void
    {
        if (! $this->has($table)) {
            return;
        }
        $rows = $this->r->select("SELECT id, \"{$col}\" AS v FROM \"{$table}\" WHERE \"{$col}\" IS NULL OR \"{$col}\" NOT IN ".$this->inList($allowed).' ORDER BY id');
        foreach ($rows as $row) {
            $this->f($entity, (int) $row['id'], $rule, $sev, $resolution, "{$col} has unsupported value '".mb_substr((string) $row['v'], 0, 40)."'", $row['v'], $default);
            if ($skip) {
                $this->skipped[$entity][(int) $row['id']] = true;
            }
        }
    }

    /**
     * Collation KEYS of the values held by rows already in the target that are NOT part of the source id set
     * (what the unique index would compare against).
     *
     * @return array<string, true>
     */
    private function targetTaken(string $table, string $col, array $sourceIds): array
    {
        if (! $this->targetChecks) {
            return [];
        }
        $sourceIds = array_flip($sourceIds);
        $values = [];
        foreach (DB::table($table)->select('id', $col)->whereNotNull($col)->cursor() as $row) {
            if (! isset($sourceIds[(int) $row->id])) {
                $values[] = (string) $row->$col;
            }
        }

        return $this->keysOf($table, $col, $values);
    }

    /** @param list<string> $values @return array<string, true> */
    private function keysOf(string $table, string $col, array $values): array
    {
        $c = Collation::forColumn($table, $col);
        $c->prime($values);
        $keys = [];
        foreach ($values as $v) {
            $keys[$c->key($v)] = true;
        }

        return $keys;
    }

    /**
     * Unique text columns (names, gateway ids): values that the target's collation treats as equal (case, accents,
     * trailing spaces) would abort the import with a duplicate-key error, so the later rows (highest id) get a
     * deterministic suffix and the finding is recorded exactly like a slug collision.
     *
     * @param  array<int, string>  $rows  source id => value
     * @param  string  $style  'name' => "Name (2)", 'code' => "value~dup<id>"
     */
    private function planUnique(string $entity, string $table, string $col, array $rows, int $max, string $rule, string $style, string $reason): void
    {
        $c = Collation::forColumn($table, $col);
        $c->prime($rows);
        $taken = $this->targetTaken($table, $col, array_keys($rows));
        ksort($rows);
        $dups = [];
        foreach ($rows as $id => $v) {
            $k = $c->key($v);
            if (isset($taken[$k])) {
                $dups[$id] = $v;
            } else {
                $taken[$k] = true;
            }
        }
        foreach ($dups as $id => $v) {
            $n = 2;
            do {
                $suffix = $style === 'name' ? " ({$n})" : "~dup{$id}".($n > 2 ? '-'.$n : '');
                $cand = mb_substr($v, 0, max(1, $max - mb_strlen($suffix))).$suffix;
                $n++;
            } while (isset($taken[$c->key($cand)]));
            $taken[$c->key($cand)] = true;
            $this->p->values["{$entity}.{$col}"][$id] = $cand;
            $this->f($entity, $id, $rule, 'blocking', 'deterministic suffix appended (lowest id keeps the original)', $reason, $style === 'name' ? $v : '(gateway id)', $style === 'name' ? $cand : '(suffixed)');
        }
    }

    /**
     * Strict MySQL rejects values longer than their column (VARCHAR: characters, TEXT: 65535 bytes). Every such value is
     * reported (blocking: the stored value differs from the source) and, with --resolve-defaults, truncated to the limit;
     * the untruncated original stays in the legacy database.
     */
    private function checkLengths(string $entity): void
    {
        $imp = new (Importer::REGISTRY[$entity]);
        $table = $imp->sourceTable();
        if (! $this->has($table)) {
            return;
        }
        $target = $imp->targetTable();
        $limits = [];
        foreach ($imp->columns() as $c) {
            if (in_array($c, ['id', 'slug', 'email', 'phone'], true)) {
                continue; // planned separately (slug/email/phone planners already respect the limit)
            }
            if ($lim = TargetSchema::stringLimit($target, $c)) {
                $limits[$c] = $lim;
            }
        }
        if (! $limits) {
            return;
        }
        $sel = ['"id"'];
        $cond = [];
        foreach ($limits as $c => [$kind, $n]) {
            $expr = ($kind === 'chars' ? 'char_length' : 'octet_length').'("'.$c.'"::text)';
            $sel[] = "CASE WHEN {$expr} > {$n} THEN {$expr} END AS \"{$c}\"";
            $cond[] = "{$expr} > {$n}";
        }
        $rows = $this->r->select('SELECT '.implode(', ', $sel).' FROM "'.$table.'" WHERE '.implode(' OR ', $cond).' ORDER BY "id"');
        foreach ($rows as $row) {
            foreach ($limits as $c => [$kind, $n]) {
                if ($row[$c] === null) {
                    continue;
                }
                $unit = $kind === 'chars' ? 'characters' : 'bytes';
                $this->f($entity, (int) $row['id'], 'LEN-TRUNCATED', 'blocking', "{$c} truncated to {$n} {$unit} (the full original stays in the legacy database)", "{$c} is {$row[$c]} {$unit} long; the target column {$target}.{$c} holds at most {$n}", (int) $row[$c], $n);
            }
        }
    }

    private function planSlugs(string $entity, string $targetTable, array $rows, int $max, string $labelCol = 'name', ?array $externalTakenOverride = null): void
    {
        $col = Collation::forColumn($targetTable, 'slug');
        $col->prime(array_map(fn ($r) => trim((string) ($r['slug'] ?? '')), $rows));
        $ext = $externalTakenOverride ?? $this->targetTaken($targetTable, 'slug', array_keys($rows));
        $plan = SlugPlanner::plan($rows, $max, $ext, fn (string $s) => $col->key($s));
        foreach ($plan as $id => $x) {
            $this->p->slugs[$entity][$id] = $x['slug'];
            if ($x['generated']) {
                $this->f($entity, $id, 'SLUG-EMPTY', 'warning', 'slug generated from the '.$labelCol, 'source slug is empty', null, $x['slug']);
            } elseif ($x['changed']) {
                $this->f($entity, $id, 'SLUG-DUP', 'blocking', 'deterministic numeric suffix appended (highest priority / lowest id keeps the original)', 'slug collides with another row (equal under the target collation: case, accents and trailing spaces are ignored)', $x['original'], $x['slug']);
            }
        }
    }

    // ---------------------------------------------------------------- users
    private function checkUsers(): void
    {
        $rows = $this->r->select("SELECT id, email, phone, phone_verified_at IS NOT NULL AS phone_verified, role, is_active, is_staff, is_superuser, last_login,
                CASE WHEN password ~ '^pbkdf2_sha256[$][0-9]+[$][^$]+[$][A-Za-z0-9+/=]+\$' THEN 'pbkdf2_sha256'
                     WHEN password ~ '^[$]2[abxy][$][0-9]{2}[$][./A-Za-z0-9]{53}\$' THEN 'bcrypt'
                     WHEN password = '' THEN 'blank' WHEN password LIKE '!%' THEN 'unusable'
                     ELSE 'other:' || left(split_part(password, '$', 1), 20) END AS algo
            FROM accounts_user ORDER BY id");
        $this->report->stat('users_total', count($rows));

        $byRole = [];
        $algos = [];
        $isStaffOnly = 0;
        $emailGroups = [];
        $emailList = [];
        foreach ($rows as $u) {
            $id = (int) $u['id'];
            $byRole[$u['role']] = ($byRole[$u['role']] ?? 0) + 1;
            $algos[$u['algo']] = ($algos[$u['algo']] ?? 0) + 1;

            // ---- role / superuser
            $su = (bool) $u['is_superuser'];
            if ($su && $u['role'] !== 'ADMIN') {
                $this->f('users', $id, 'USR-SUPERUSER-ROLE', 'warning', 'imported with role ADMIN', "is_superuser=true but role='{$u['role']}'", $u['role'], 'ADMIN');
            }
            if (! $su && ! in_array($u['role'], Rules::roles(), true)) {
                $this->f('users', $id, 'USR-ROLE-INVALID', 'blocking', 'imported with role '.Rules::DEFAULT_ROLE.' (least privilege)', 'role is not one of ADMIN/REPORTER/USER/SUBSCRIBER', $u['role'], Rules::DEFAULT_ROLE);
            }
            if ((bool) $u['is_staff'] && ! $su && $u['role'] !== 'ADMIN') {
                $isStaffOnly++;
            }

            // ---- password
            if (! in_array($u['algo'], ['pbkdf2_sha256', 'bcrypt'], true)) {
                $this->f('users', $id, 'USR-PASSWORD-UNSUPPORTED', 'warning', 'replaced by an unusable random bcrypt hash; user must use "forgot password"', 'password hash format is '.$u['algo'].' (only pbkdf2_sha256 and bcrypt can be verified by Laravel)');
            }

            $emailList[] = $u;
        }
        // Group by what the target's unique index considers equal (collation key of the normalised address), not by PHP equality.
        $emailCol = Collation::forColumn('users', 'email');
        $emailCol->prime(array_map(fn ($u) => mb_strtolower(trim((string) $u['email'])), $emailList));
        foreach ($emailList as $u) {
            $emailGroups[$emailCol->key(mb_strtolower(trim((string) $u['email'])))][] = $u;
        }
        $this->report->stat('users_by_role', $byRole);
        $this->report->stat('users_password_algorithms', $algos);
        $this->report->stat('users_is_staff_non_admin', $isStaffOnly);

        $this->planEmails($emailGroups);
        $this->planPhones($rows);
    }

    /** @param array<string, list<array<string, mixed>>> $groups collation key => users */
    private function planEmails(array $groups): void
    {
        $col = Collation::forColumn('users', 'email');
        $normOf = fn (array $u): string => mb_strtolower(trim((string) $u['email']));
        $taken = [];
        $sourceIds = [];
        foreach ($groups as $g) {
            foreach ($g as $u) {
                $sourceIds[] = (int) $u['id'];
            }
        }
        $targetTaken = $this->targetTaken('users', 'email', $sourceIds);
        $key = fn (string $e): string => $col->key($e);

        $order = function (array $a, array $b): int {
            return ((int) $b['is_active'] <=> (int) $a['is_active'])
                ?: strcmp((string) ($b['last_login'] ?? ''), (string) ($a['last_login'] ?? ''))
                ?: ((int) $a['id'] <=> (int) $b['id']);
        };
        ksort($groups);
        // Primary rows first so aliases can never steal a real address.
        $primaries = [];
        $others = [];
        foreach ($groups as $gk => $g) {
            usort($g, $order);
            $primaries[$gk] = $g[0];
            foreach (array_slice($g, 1) as $extra) {
                $others[] = [$extra, $g[0]];
            }
        }
        foreach ($primaries as $u) {
            $id = (int) $u['id'];
            $norm = $normOf($u);
            if ($norm === '') {
                $final = "legacy-user-{$id}@invalid.local";
                $this->p->deactivate[$id] = true;
                $this->f('users', $id, 'USR-EMAIL-BLANK', 'blocking', 'placeholder address assigned and account imported inactive', 'email is blank', '(blank)', Masker::email($final));
            } elseif (isset($targetTaken[$key($norm)])) {
                $final = $this->alias($norm, $id, $taken + $targetTaken, $key);
                $this->p->deactivate[$id] = true;
                $this->f('users', $id, 'USR-EMAIL-TARGET-CONFLICT', 'blocking', 'alias address assigned and account imported inactive', 'a different user already holds this address in the target database', Masker::email($norm), Masker::email($final));
            } else {
                $final = $norm;
                if (! preg_match('/^[^@\s]+@[^@\s]+$/', $norm)) {
                    $this->f('users', $id, 'USR-EMAIL-INVALID', 'warning', 'imported as-is (lower-cased)', 'email is not a valid address', Masker::email($norm), Masker::email($norm));
                }
                if ($norm !== (string) $u['email']) {
                    $this->f('users', $id, 'USR-EMAIL-NORMALIZED', 'info', 'trimmed and lower-cased (login is case-insensitive in Laravel)', 'stored email differs from its normalised form', Masker::email($u['email']), Masker::email($norm));
                }
            }
            $taken[$key($final)] = true;
            $this->p->emails[$id] = $final;
        }
        foreach ($others as [$u, $primary]) {
            $id = (int) $u['id'];
            $norm = $normOf($u);
            $final = $this->alias($norm === '' ? "legacy-user-{$id}@invalid.local" : $norm, $id, $taken + $targetTaken, $key);
            $taken[$key($final)] = true;
            $this->p->emails[$id] = $final;
            $this->p->deactivate[$id] = true;
            $this->f('users', $id, $norm === '' ? 'USR-EMAIL-BLANK' : 'USR-EMAIL-CASE-DUP', 'blocking', 'imported inactive with a unique alias address; the primary keeps the original', "email equals user {$primary['id']}'s under the target collation (differs only by case, accents or surrounding spaces)", Masker::email($norm), Masker::email($final));
        }
        $this->report->stat('users_email_duplicates', count($others));
    }

    /** @param callable(string): string $key collation key */
    private function alias(string $email, int $id, array $taken, callable $key): string
    {
        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);
        $domain = $at === false ? 'invalid.local' : substr($email, $at + 1);
        $n = 1;
        do {
            $suffix = "+dup{$id}".($n > 1 ? "-{$n}" : '');
            // users.email is varchar(254): shorten the local part rather than overflow
            $room = max(1, 254 - mb_strlen($domain) - 1 - mb_strlen($suffix));
            $cand = mb_substr($local, 0, $room).$suffix.'@'.$domain;
            $n++;
        } while (isset($taken[$key($cand)]));

        return $cand;
    }

    private function planPhones(array $rows): void
    {
        $cands = []; // digit key => list of [user, phone]
        $sourceIds = array_map(fn ($u) => (int) $u['id'], $rows);
        $targetTaken = [];
        $phoneCol = Collation::forColumn('users', 'phone');
        // Key of a phone: its digits; numberless (malformed) values fall back to the column-collation key so that
        // 'CALL-ME' and 'call-me ' (equal for the unique index) are detected too.
        $phoneKey = function (string $v) use ($phoneCol): string {
            $d = preg_replace('/\D/', '', $v);

            return $d !== '' ? $d : 'txt:'.$phoneCol->key($v);
        };
        if ($this->targetChecks) {
            $sids = array_flip($sourceIds);
            foreach (DB::table('users')->select('id', 'phone')->whereNotNull('phone')->cursor() as $t) {
                if (! isset($sids[(int) $t->id])) {
                    $targetTaken[$phoneKey((string) $t->phone)] = true;
                }
            }
        }
        foreach ($rows as $u) {
            $id = (int) $u['id'];
            $raw = $u['phone'];
            if ($raw === null) {
                continue;
            }
            $trim = trim((string) $raw);
            if ($trim === '') {
                $this->p->phones[$id] = null;
                $this->f('users', $id, 'USR-PHONE-BLANK', 'warning', 'blank string stored as NULL', "phone is an empty string (Django stored '' instead of NULL)", '(blank)', null);

                continue;
            }
            $n = Rules::normalizePhone($trim);
            $value = $n['value'];
            if (! $n['valid']) {
                if (strlen($value) > 20) {
                    $this->p->phones[$id] = null;
                    $this->f('users', $id, 'USR-PHONE-TOOLONG', 'blocking', 'phone cleared (does not fit varchar(20)); original stays in the legacy DB', 'phone is malformed and longer than 20 characters', Masker::phone($trim), null);

                    continue;
                }
                $this->f('users', $id, 'USR-PHONE-MALFORMED', 'warning', 'imported as-is', 'phone is not E.164-like (expected optional + and 7-15 digits)', Masker::phone($trim), Masker::phone($value));
            } elseif ($n['changed']) {
                $this->f('users', $id, 'USR-PHONE-NORMALIZED', 'info', 'separators removed', 'phone contained spaces/dashes/brackets', Masker::phone($trim), Masker::phone($value));
            }
            if (strlen($value) > 20) {
                $value = mb_substr($value, 0, 20);
            }
            $this->p->phones[$id] = $value;
            $cands[$phoneKey($value)][] = [$u, $value];
        }
        foreach ($cands as $key => $list) {
            if (count($list) < 2 && ! isset($targetTaken[$key])) {
                continue;
            }
            usort($list, function ($a, $b) {
                $ap = ! isset($this->p->deactivate[(int) $a[0]['id']]);
                $bp = ! isset($this->p->deactivate[(int) $b[0]['id']]);

                return ((int) $bp <=> (int) $ap)
                    ?: ((int) $b[0]['phone_verified'] <=> (int) $a[0]['phone_verified'])
                    ?: ((int) $b[0]['is_active'] <=> (int) $a[0]['is_active'])
                    ?: ((int) $a[0]['id'] <=> (int) $b[0]['id']);
            });
            $targetHolds = isset($targetTaken[$key]);
            foreach ($list as $i => [$u, $value]) {
                if ($i === 0 && ! $targetHolds) {
                    continue;
                }
                $id = (int) $u['id'];
                $this->p->phones[$id] = null;
                $this->f('users', $id, 'USR-PHONE-DUP', 'blocking', 'phone cleared (and phone verification dropped) on the duplicate; the primary keeps it', $targetHolds ? 'phone number already held by another user in the target' : "same phone number as user {$list[0][0]['id']}", Masker::phone($value), null);
            }
        }
        foreach ($rows as $u) {
            if ($u['phone_verified'] && ($this->p->phones[(int) $u['id']] ?? $u['phone']) === null) {
                $this->f('users', (int) $u['id'], 'USR-PHONE-VERIFIED-NO-PHONE', 'warning', 'phone_verified_at imported as NULL', 'phone_verified_at is set but no usable phone remains');
            }
        }
    }

    // ---------------------------------------------------------------- taxonomy
    private function checkIndustries(): void
    {
        $rows = [];
        foreach ($this->r->select('SELECT id, name, slug FROM categories_industry ORDER BY id') as $x) {
            $rows[(int) $x['id']] = ['slug' => $x['slug'], 'name' => $x['name']];
        }
        $this->planSlugs('industries', 'industries', $rows, 120);
        $this->planUnique('industries', 'industries', 'name', array_map(fn ($r) => (string) $r['name'], $rows), 100, 'IND-NAME-DUP', 'name', 'industry name equals another industry name under the target collation');
    }

    private function checkCategories(): void
    {
        $rows = [];
        foreach ($this->r->select('SELECT id, name, slug FROM categories_category ORDER BY id') as $x) {
            $rows[(int) $x['id']] = ['slug' => $x['slug'], 'name' => $x['name']];
        }
        $this->planSlugs('categories', 'categories', $rows, 120);
        $this->planUnique('categories', 'categories', 'name', array_map(fn ($r) => (string) $r['name'], $rows), 100, 'CAT-NAME-DUP', 'name', 'category name equals another category name under the target collation');
        $this->fkRule('categories', 'categories_category', 'industry_id', 'industries', 'categories_industry', 'null', 'TAX-CAT-INDUSTRY-ORPHAN', 'industry_id imported as NULL (legacy state)');
        foreach ($this->r->select('SELECT id FROM categories_category WHERE industry_id IS NULL ORDER BY id') as $x) {
            $this->f('categories', (int) $x['id'], 'TAX-CAT-NO-INDUSTRY', 'warning', 'imported with industry_id NULL (target allows it for legacy rows); no industry is invented', 'category has no industry (pre-hierarchy legacy row)');
        }
    }

    private function checkSubcategories(): void
    {
        $this->fkRule('subcategories', 'categories_subcategory', 'category_id', 'categories', 'categories_category', 'skip', 'TAX-SUB-CATEGORY-ORPHAN', 'row skipped (listed under unresolved records); articles pointing at it fall back to their category');
        $groups = [];
        $allIds = [];
        foreach ($this->r->select('SELECT id, category_id, name, slug FROM categories_subcategory ORDER BY id') as $x) {
            $allIds[(int) $x['id']] = true;
            if (isset($this->skipped['subcategories'][(int) $x['id']])) {
                continue;
            }
            $groups[(int) $x['category_id']][(int) $x['id']] = ['slug' => $x['slug'], 'name' => $x['name']];
        }
        // Slugs are unique per category: only rows of the same category in the target can collide.
        $targetByCategory = [];
        if ($this->targetChecks) {
            $vals = [];
            foreach (DB::table('subcategories')->select('id', 'category_id', 'slug')->get() as $t) {
                if (! isset($allIds[(int) $t->id])) {
                    $vals[(int) $t->category_id][] = (string) $t->slug;
                }
            }
            foreach ($vals as $cid => $list) {
                $targetByCategory[$cid] = $this->keysOf('subcategories', 'slug', $list);
            }
        }
        foreach ($groups as $cid => $rows) {
            $this->planSlugs('subcategories', 'subcategories', $rows, 170, 'name', $targetByCategory[$cid] ?? []);
        }
    }

    private function checkTags(): void
    {
        $rows = [];
        foreach ($this->r->select('SELECT id, name, slug FROM categories_tag ORDER BY id') as $x) {
            $rows[(int) $x['id']] = ['slug' => $x['slug'], 'name' => $x['name']];
        }
        $this->planSlugs('tags', 'tags', $rows, 80);
        // tags.name is UNIQUE and the target collation ignores case/accents: 'News' and 'news' can no longer coexist.
        $this->planUnique('tags', 'tags', 'name', array_map(fn ($r) => (string) $r['name'], $rows), 60, 'TAG-NAME-CASE-DUP', 'name', 'tag name equals another tag name under the target collation (case/accent/trailing-space insensitive)');
    }

    private function checkSubscriptionPlans(): void
    {
        $rows = [];
        foreach ($this->r->select('SELECT id, name, slug FROM subscriptions_subscriptionplan ORDER BY id') as $x) {
            $rows[(int) $x['id']] = ['slug' => $x['slug'], 'name' => $x['name']];
        }
        $this->planSlugs('subscription_plans', 'subscription_plans', $rows, 120);
    }

    // ---------------------------------------------------------------- articles
    private function checkArticles(): void
    {
        $t = 'articles_article';
        $this->fkRule('articles', $t, 'author_id', 'users', 'accounts_user', 'skip', 'ART-AUTHOR-ORPHAN', 'article skipped together with its images/tags/reviews/schedules/notifications/analytics/AI rows (see unresolved records)');
        $this->fkRule('articles', $t, 'assigned_reporter_id', 'users', 'accounts_user', 'null', 'ART-ASSIGNED-ORPHAN', 'assigned_reporter_id imported as NULL');
        $this->fkRule('articles', $t, 'subcategory_id', 'subcategories', 'categories_subcategory', 'null', 'ART-SUBCATEGORY-ORPHAN', 'subcategory_id imported as NULL (falls back to the legacy category)');
        $this->fkRule('articles', $t, 'category_id', 'categories', 'categories_category', 'null', 'ART-CATEGORY-ORPHAN', 'category_id imported as NULL');
        // (skip-mode 'null' rules above also flag rows whose parent subcategory was skipped)
        // Only 'skip' mode writes to $this->skipped, so author-orphans are the only skipped articles.

        $this->enumRule('articles', $t, 'status', Rules::articleStatuses(), 'ART-STATUS-INVALID', 'blocking', 'imported with status '.Rules::DEFAULT_ARTICLE_STATUS.' (never accidentally public)', false, Rules::DEFAULT_ARTICLE_STATUS);
        $this->enumRule('articles', $t, 'access_level', Rules::accessLevels(), 'ART-ACCESS-INVALID', 'blocking', 'imported with access_level '.Rules::DEFAULT_ACCESS_LEVEL.' (body never leaks)', false, Rules::DEFAULT_ACCESS_LEVEL);

        foreach ($this->r->select("SELECT a.id FROM {$t} a WHERE a.subcategory_id IS NULL AND a.category_id IS NOT NULL ORDER BY a.id") as $x) {
            $this->f('articles', (int) $x['id'], 'ART-NO-SUBCATEGORY', 'warning', 'imported with subcategory_id NULL and the legacy category kept (needs an editor decision)', 'article has a category but no subcategory');
        }
        foreach ($this->r->select("SELECT a.id FROM {$t} a WHERE a.subcategory_id IS NULL AND a.category_id IS NULL ORDER BY a.id") as $x) {
            $this->f('articles', (int) $x['id'], 'ART-NO-TAXONOMY', 'warning', 'imported unclassified (no category/subcategory is invented)', 'article has neither a subcategory nor a category');
        }
        foreach ($this->r->select("SELECT a.id, a.category_id, s.category_id AS derived FROM {$t} a JOIN categories_subcategory s ON s.id = a.subcategory_id WHERE a.category_id IS DISTINCT FROM s.category_id ORDER BY a.id") as $x) {
            $this->f('articles', (int) $x['id'], 'ART-CATEGORY-MISMATCH', 'warning', 'category_id re-derived from the subcategory (Django save() rule)', 'category_id disagrees with subcategory.category_id', $x['category_id'], $x['derived']);
        }
        foreach ($this->r->select("SELECT id FROM {$t} WHERE status = 'PUBLISHED' AND published_at IS NULL ORDER BY id") as $x) {
            $this->f('articles', (int) $x['id'], 'ART-PUBLISHED-NO-DATE', 'warning', 'imported as-is (no date is invented)', 'status PUBLISHED but published_at is NULL');
        }
        foreach ($this->r->select("SELECT id FROM {$t} WHERE status = 'SCHEDULED' AND scheduled_publish_at IS NULL ORDER BY id") as $x) {
            $this->f('articles', (int) $x['id'], 'ART-SCHEDULED-NO-DATE', 'warning', 'imported as-is', 'status SCHEDULED but scheduled_publish_at is NULL');
        }
        foreach ($this->r->select("SELECT id, jsonb_typeof(faqs) AS ty FROM {$t} WHERE jsonb_typeof(faqs) IS DISTINCT FROM 'array' ORDER BY id") as $x) {
            $this->f('articles', (int) $x['id'], 'ART-FAQS-INVALID', 'warning', 'faqs imported as an empty list', 'faqs JSON is '.($x['ty'] ?? 'null').', expected an array', $x['ty'], '[]');
        }

        $rows = [];
        foreach ($this->r->select("SELECT id, slug, title, status FROM {$t} ORDER BY id") as $x) {
            if (isset($this->skipped['articles'][(int) $x['id']])) {
                continue;
            }
            $rows[(int) $x['id']] = ['slug' => $x['slug'], 'name' => $x['title'], 'priority' => $x['status'] === 'PUBLISHED' ? 1 : 0];
        }
        $this->planSlugs('articles', 'articles', $rows, 280, 'title');

        $this->report->stat('articles_by_status', array_column($this->r->select("SELECT status, count(*) AS n FROM {$t} GROUP BY status ORDER BY status"), 'n', 'status'));
        $this->report->stat('articles_without_tags', (int) $this->r->scalar("SELECT count(*) FROM {$t} a WHERE NOT EXISTS (SELECT 1 FROM articles_article_tags x WHERE x.article_id = a.id)"));
    }

    private function checkArticleTags(): void
    {
        $t = 'articles_article_tags';
        $this->fkRule('article_tags', $t, 'article_id', 'articles', 'articles_article', 'skip', 'ART-TAG-ORPHAN', 'tag link skipped (see unresolved records)');
        $this->fkRule('article_tags', $t, 'tag_id', 'tags', 'categories_tag', 'skip', 'ART-TAG-ORPHAN', 'tag link skipped (see unresolved records)');
        foreach ($this->r->select("SELECT array_to_string(array_agg(id ORDER BY id), ',') AS ids FROM {$t} GROUP BY article_id, tag_id HAVING count(*) > 1") as $g) {
            $ids = array_map('intval', explode(',', $g['ids']));
            foreach (array_slice($ids, 1) as $id) {
                $this->f('article_tags', $id, 'ART-TAG-DUP', 'warning', 'duplicate link skipped', "duplicate (article, tag) pair of link {$ids[0]}");
            }
        }
    }

    private function checkArticleImages(): void
    {
        $t = 'media_articleimage';
        $this->fkRule('article_images', $t, 'article_id', 'articles', 'articles_article', 'skip', 'IMG-ARTICLE-ORPHAN', 'image skipped (see unresolved records)');
        // uploader is mandatory in the target: fall back to the article author.
        foreach ($this->r->select("SELECT i.id, i.uploaded_by_id FROM {$t} i WHERE NOT EXISTS (SELECT 1 FROM accounts_user u WHERE u.id = i.uploaded_by_id) ORDER BY i.id") as $x) {
            $this->f('article_images', (int) $x['id'], 'IMG-UPLOADER-ORPHAN', 'blocking', 'uploaded_by set to the article author', "uploader {$x['uploaded_by_id']} does not exist");
        }
        if ($this->has('media_mediametadata')) {
            $n = (int) $this->r->scalar('SELECT count(*) FROM media_mediametadata m WHERE NOT EXISTS (SELECT 1 FROM media_articleimage i WHERE i.id = m.image_id)');
            foreach ($this->r->select('SELECT m.id FROM media_mediametadata m WHERE NOT EXISTS (SELECT 1 FROM media_articleimage i WHERE i.id = m.image_id) ORDER BY m.id') as $x) {
                $this->f('article_images', (int) $x['id'], 'IMG-META-ORPHAN', 'blocking', 'metadata row skipped (see unresolved records)', 'media_mediametadata row references a missing image');
            }
            $this->report->stat('image_metadata_orphans', $n);
            $this->report->stat('images_without_metadata', (int) $this->r->scalar("SELECT count(*) FROM {$t} i WHERE NOT EXISTS (SELECT 1 FROM media_mediametadata m WHERE m.image_id = i.id)"));
        }

        // exactly one featured image per article that has images
        $byArticle = [];
        foreach ($this->r->select("SELECT i.id, i.article_id, i.is_featured, i.display_order, i.created_at, i.updated_at FROM {$t} i WHERE EXISTS (SELECT 1 FROM articles_article a WHERE a.id = i.article_id) ORDER BY i.article_id, i.id") as $x) {
            if (isset($this->skipped['articles'][(int) $x['article_id']])) {
                continue;
            }
            $byArticle[(int) $x['article_id']][] = $x;
        }
        foreach ($byArticle as $aid => $imgs) {
            $featured = array_values(array_filter($imgs, fn ($i) => $i['is_featured']));
            if (count($featured) === 1) {
                $this->p->featured[$aid] = (int) $featured[0]['id'];

                continue;
            }
            if (count($featured) === 0) {
                usort($imgs, fn ($a, $b) => ((int) $a['display_order'] <=> (int) $b['display_order']) ?: strcmp($a['created_at'], $b['created_at']) ?: ((int) $a['id'] <=> (int) $b['id']));
                $pick = (int) $imgs[0]['id'];
                $this->f('article_images', $pick, 'IMG-NO-FEATURED', 'warning', 'first image (display_order, created_at, id) marked featured', "article {$aid} has images but none is featured", false, true);
            } else {
                usort($featured, fn ($a, $b) => strcmp($b['updated_at'], $a['updated_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
                $pick = (int) $featured[0]['id'];
                foreach (array_slice($featured, 1) as $dem) {
                    $this->f('article_images', (int) $dem['id'], 'IMG-MULTI-FEATURED', 'warning', "featured flag removed; most recently updated image {$pick} stays featured", "article {$aid} has ".count($featured).' featured images', true, false);
                }
            }
            $this->p->featured[$aid] = $pick;
        }
    }

    // ---------------------------------------------------------------- workflow
    private function checkArticleReviews(): void
    {
        $t = 'reporters_articlereview';
        $this->fkRule('article_reviews', $t, 'article_id', 'articles', 'articles_article', 'skip', 'REV-ARTICLE-ORPHAN', 'review row skipped (see unresolved records)');
        $this->fkRule('article_reviews', $t, 'reviewer_id', 'users', 'accounts_user', 'null', 'REV-REVIEWER-ORPHAN', 'reviewer_id imported as NULL');
        $this->enumRule('article_reviews', $t, 'action', Rules::reviewActions(), 'REV-VALUE-INVALID', 'blocking', 'review row skipped (see unresolved records)', true);
        $this->enumRule('article_reviews', $t, 'from_status', Rules::articleStatuses(), 'REV-VALUE-INVALID', 'blocking', 'review row skipped (see unresolved records)', true);
        $this->enumRule('article_reviews', $t, 'to_status', Rules::articleStatuses(), 'REV-VALUE-INVALID', 'blocking', 'review row skipped (see unresolved records)', true);
    }

    private function checkPublishingSchedules(): void
    {
        $t = 'reporters_publishingschedule';
        $this->fkRule('publishing_schedules', $t, 'article_id', 'articles', 'articles_article', 'skip', 'SCH-ARTICLE-ORPHAN', 'schedule row skipped (see unresolved records)');
        $this->fkRule('publishing_schedules', $t, 'scheduled_by_id', 'users', 'accounts_user', 'skip', 'SCH-USER-ORPHAN', 'schedule row skipped (see unresolved records)');
        $this->enumRule('publishing_schedules', $t, 'status', Rules::SCHEDULE_STATUSES, 'SCH-STATUS-INVALID', 'blocking', 'imported as CANCELLED', false, Rules::DEFAULT_SCHEDULE_STATUS);
        foreach ($this->r->select("SELECT array_to_string(array_agg(id ORDER BY created_at DESC, id DESC), ',') AS ids FROM {$t} WHERE status = 'PENDING' GROUP BY article_id HAVING count(*) > 1") as $g) {
            $ids = array_map('intval', explode(',', $g['ids']));
            foreach (array_slice($ids, 1) as $id) {
                $this->p->scheduleCancel[$id] = true;
                $this->f('publishing_schedules', $id, 'SCH-MULTI-PENDING', 'blocking', "older PENDING schedule imported as CANCELLED (newest {$ids[0]} stays PENDING; one PENDING per article)", 'article has several PENDING schedules', 'PENDING', 'CANCELLED');
            }
        }
        foreach ($this->r->select("SELECT s.id, a.status FROM {$t} s JOIN articles_article a ON a.id = s.article_id WHERE s.status = 'PENDING' AND a.status <> 'SCHEDULED' ORDER BY s.id") as $x) {
            $this->f('publishing_schedules', (int) $x['id'], 'SCH-PENDING-STATE', 'warning', 'imported as-is', "schedule is PENDING but its article status is {$x['status']}");
        }
    }

    private function checkReporterAssignments(): void
    {
        $t = 'reporters_reportercategoryassignment';
        $this->fkRule('reporter_assignments', $t, 'reporter_id', 'users', 'accounts_user', 'skip', 'RCA-REPORTER-ORPHAN', 'assignment skipped (see unresolved records)');
        $this->fkRule('reporter_assignments', $t, 'category_id', 'categories', 'categories_category', 'skip', 'RCA-CATEGORY-ORPHAN', 'assignment skipped (see unresolved records)');
        $this->fkRule('reporter_assignments', $t, 'assigned_by_id', 'users', 'accounts_user', 'null', 'RCA-ASSIGNER-ORPHAN', 'assigned_by_id imported as NULL');
        foreach ($this->r->select("SELECT c.id FROM {$t} c JOIN accounts_user u ON u.id = c.reporter_id WHERE u.role <> 'REPORTER' AND NOT u.is_superuser ORDER BY c.id") as $x) {
            $this->f('reporter_assignments', (int) $x['id'], 'RCA-NOT-REPORTER', 'warning', 'imported as-is', 'assignee does not have role REPORTER');
        }
    }

    // ---------------------------------------------------------------- subscriptions / payments
    private function checkSubscriptions(): void
    {
        $t = 'subscriptions_subscription';
        $this->fkRule('subscriptions', $t, 'user_id', 'users', 'accounts_user', 'skip', 'SUB-USER-ORPHAN', 'subscription skipped with its payments (see unresolved records)');
        $this->fkRule('subscriptions', $t, 'plan_id', 'subscription_plans', 'subscriptions_subscriptionplan', 'skip', 'SUB-PLAN-ORPHAN', 'subscription skipped with its payments (see unresolved records)');
        $this->enumRule('subscriptions', $t, 'status', Rules::SUBSCRIPTION_STATUSES, 'SUB-STATUS-INVALID', 'blocking', 'imported as CANCELLED (grants no entitlement)', false, Rules::DEFAULT_SUBSCRIPTION_STATUS);

        // Decision: PENDING subscriptions are imported as PENDING (as-is). No state is invented; the
        // Laravel lifecycle jobs / support decide. Reported here with age and payment evidence.
        $paidCol = $this->has('subscriptions_payment') ? "EXISTS (SELECT 1 FROM subscriptions_payment p WHERE p.subscription_id = s.id AND p.status = 'PAID')" : 'false';
        $n = 0;
        foreach ($this->r->select("SELECT s.id, floor(extract(epoch FROM (now() - s.created_at)) / 86400)::int AS age_days, {$paidCol} AS has_paid FROM {$t} s WHERE s.status = 'PENDING' ORDER BY s.id") as $x) {
            $n++;
            $this->f('subscriptions', (int) $x['id'], 'SUB-PENDING', 'warning', 'imported as PENDING (as-is; checkout never verified)', "PENDING subscription, {$x['age_days']} day(s) old");
            if ($x['has_paid']) {
                $this->f('subscriptions', (int) $x['id'], 'SUB-PENDING-PAID', 'warning', 'imported as PENDING; NOT auto-activated - reconcile manually with Razorpay', 'a PAID payment exists for this PENDING subscription');
            }
        }
        $this->report->stat('subscriptions_pending', $n);
        foreach ($this->r->select("SELECT id FROM {$t} WHERE status = 'ACTIVE' AND (expires_at IS NULL OR expires_at < now()) ORDER BY id") as $x) {
            $this->f('subscriptions', (int) $x['id'], 'SUB-ACTIVE-STALE', 'warning', 'imported as ACTIVE (expiry job will move it to EXPIRED)', 'ACTIVE subscription is already past (or without) expires_at');
        }
        foreach ($this->r->select("SELECT user_id, count(*) AS n FROM {$t} WHERE status = 'ACTIVE' AND expires_at > now() GROUP BY user_id HAVING count(*) > 1 ORDER BY user_id") as $x) {
            $this->f('subscriptions', null, 'SUB-MULTI-ACTIVE', 'info', 'imported as-is', "user {$x['user_id']} has {$x['n']} concurrently ACTIVE subscriptions");
        }
    }

    private function checkPayments(): void
    {
        $t = 'subscriptions_payment';
        $this->fkRule('payments', $t, 'subscription_id', 'subscriptions', 'subscriptions_subscription', 'skip', 'PAY-SUBSCRIPTION-ORPHAN', 'payment skipped (see unresolved records)');
        $this->fkRule('payments', $t, 'user_id', 'users', 'accounts_user', 'skip', 'PAY-USER-ORPHAN', 'payment skipped (see unresolved records)');
        $ids = [];
        foreach ($this->r->select("SELECT id, razorpay_order_id FROM {$t} ORDER BY id") as $x) {
            if (! isset($this->skipped['payments'][(int) $x['id']])) {
                $ids[(int) $x['id']] = (string) $x['razorpay_order_id'];
            }
        }
        $this->planUnique('payments', 'payments', 'razorpay_order_id', $ids, 100, 'PAY-ORDERID-DUP', 'code', 'razorpay_order_id equals another payment\'s order id under the target collation');
        $this->enumRule('payments', $t, 'status', Rules::PAYMENT_STATUSES, 'PAY-STATUS-INVALID', 'blocking', 'imported as FAILED', false, Rules::DEFAULT_PAYMENT_STATUS);
        foreach ($this->r->select("SELECT p.id FROM {$t} p JOIN subscriptions_subscription s ON s.id = p.subscription_id WHERE s.user_id <> p.user_id ORDER BY p.id") as $x) {
            $this->f('payments', (int) $x['id'], 'PAY-USER-MISMATCH', 'info', 'imported as-is', 'payment.user differs from subscription.user');
        }
    }

    private function checkNotifications(): void
    {
        $t = 'notifications_notification';
        $this->fkRule('notifications', $t, 'recipient_id', 'users', 'accounts_user', 'skip', 'NTF-RECIPIENT-ORPHAN', 'notification skipped (see unresolved records)');
        $this->fkRule('notifications', $t, 'article_id', 'articles', 'articles_article', 'null', 'NTF-ARTICLE-ORPHAN', 'article_id imported as NULL');
        $this->enumRule('notifications', $t, 'notification_type', Rules::notificationTypes(), 'NTF-TYPE-INVALID', 'blocking', 'notification skipped (see unresolved records)', true);
    }

    private function checkAdvertisements(): void
    {
        $t = 'advertisements_advertisement';
        if (! $this->has($t)) {
            return;
        }
        foreach ($this->r->select("SELECT id, placement FROM {$t} WHERE placement NOT IN ".$this->inList(Rules::placements()).' ORDER BY id') as $x) {
            if (isset(Rules::PLACEMENT_RENAMES[$x['placement']])) {
                $this->f('advertisements', (int) $x['id'], 'ADS-PLACEMENT-RENAMED', 'blocking', 'placement mapped to '.Rules::PLACEMENT_RENAMES[$x['placement']], 'placement uses the pre-rename value', $x['placement'], Rules::PLACEMENT_RENAMES[$x['placement']]);
            } else {
                $this->f('advertisements', (int) $x['id'], 'ADS-PLACEMENT-INVALID', 'blocking', 'advertisement skipped (see unresolved records)', 'placement is not a known value', $x['placement']);
            }
        }
        foreach ($this->r->select("SELECT id FROM {$t} WHERE start_at >= end_at ORDER BY id") as $x) {
            $this->f('advertisements', (int) $x['id'], 'ADS-DATES', 'warning', 'imported as-is', 'start_at is not before end_at');
        }
        $this->report->stat('advertisements_by_placement', array_column($this->r->select("SELECT placement, count(*) AS n FROM {$t} GROUP BY placement ORDER BY placement"), 'n', 'placement'));
    }

    private function checkArticleDailyViews(): void
    {
        if ($this->has('analytics_articledailyview')) {
            $this->fkRule('article_daily_views', 'analytics_articledailyview', 'article_id', 'articles', 'articles_article', 'skip', 'VIEW-ARTICLE-ORPHAN', 'row skipped (see unresolved records)');
        }
    }

    private function checkAiAnalyses(): void
    {
        if ($this->has('ai_aianalysisresult')) {
            $this->fkRule('ai_analyses', 'ai_aianalysisresult', 'article_id', 'articles', 'articles_article', 'skip', 'AI-ARTICLE-ORPHAN', 'row skipped (see unresolved records)');
            $this->fkRule('ai_analyses', 'ai_aianalysisresult', 'requested_by_id', 'users', 'accounts_user', 'null', 'AI-REQUESTER-ORPHAN', 'requested_by_id imported as NULL');
            $n = (int) $this->r->scalar("SELECT count(*) FROM ai_aianalysisresult WHERE provider <> 'OPENAI'");
            $this->report->stat('ai_analyses_non_openai_provider', $n);
        }
    }

    private function checkPlagiarismChecks(): void
    {
        if ($this->has('ai_plagiarismcheckresult')) {
            $this->fkRule('plagiarism_checks', 'ai_plagiarismcheckresult', 'article_id', 'articles', 'articles_article', 'skip', 'PLG-ARTICLE-ORPHAN', 'row skipped (see unresolved records)');
            $ids = [];
            foreach ($this->r->select('SELECT id, scan_id FROM ai_plagiarismcheckresult ORDER BY id') as $x) {
                if (! isset($this->skipped['plagiarism_checks'][(int) $x['id']])) {
                    $ids[(int) $x['id']] = (string) $x['scan_id'];
                }
            }
            $this->planUnique('plagiarism_checks', 'plagiarism_check_results', 'scan_id', $ids, 100, 'PLG-SCANID-DUP', 'code', 'scan_id equals another scan id under the target collation');
            $this->fkRule('plagiarism_checks', 'ai_plagiarismcheckresult', 'requested_by_id', 'users', 'accounts_user', 'null', 'PLG-REQUESTER-ORPHAN', 'requested_by_id imported as NULL');
        }
    }
}
