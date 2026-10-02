<?php

namespace App\Services\Import;

use App\Services\Import\Entities\AbstractEntityImporter;
use App\Services\Import\Entities\AdvertisementImporter;
use App\Services\Import\Entities\AiAnalysisImporter;
use App\Services\Import\Entities\ArticleDailyViewImporter;
use App\Services\Import\Entities\ArticleImageImporter;
use App\Services\Import\Entities\ArticleImporter;
use App\Services\Import\Entities\ArticleReviewImporter;
use App\Services\Import\Entities\ArticleTagImporter;
use App\Services\Import\Entities\CategoryImporter;
use App\Services\Import\Entities\IndustryImporter;
use App\Services\Import\Entities\NotificationImporter;
use App\Services\Import\Entities\PaymentImporter;
use App\Services\Import\Entities\PlagiarismCheckImporter;
use App\Services\Import\Entities\PublishingScheduleImporter;
use App\Services\Import\Entities\ReporterAssignmentImporter;
use App\Services\Import\Entities\SubcategoryImporter;
use App\Services\Import\Entities\SubscriptionImporter;
use App\Services\Import\Entities\SubscriptionPlanImporter;
use App\Services\Import\Entities\TagImporter;
use App\Services\Import\Entities\UserImporter;
use App\Services\Import\Support\Collation;
use App\Services\Import\Support\DatabaseIdentity;
use App\Services\Import\Support\ImportAbort;
use App\Services\Import\Support\TargetSchema;
use App\Services\Search\ArticleSearchIndexer;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates: guards -> read-only legacy connection (PostgreSQL, Django) -> pre-import validation ->
 * (blocked?) -> per-entity transactional import into the MySQL/MariaDB target -> AUTO_INCREMENT reset ->
 * search reindex -> relationship validation -> report. The Django database is only ever READ.
 *
 * Exit codes: 0 ok, 2 blocked by findings, 3 target not empty, 4 relationship validation failed,
 * 10 legacy connection/schema problem, 11 read-only guarantee failed, 12 source == target / unsupported target engine, 20 entity import failed.
 */
class Importer
{
    /** @var array<string, class-string<AbstractEntityImporter>> import order */
    public const REGISTRY = [
        'users' => UserImporter::class,
        'industries' => IndustryImporter::class,
        'categories' => CategoryImporter::class,
        'subcategories' => SubcategoryImporter::class,
        'tags' => TagImporter::class,
        'subscription_plans' => SubscriptionPlanImporter::class,
        'articles' => ArticleImporter::class,
        'article_tags' => ArticleTagImporter::class,
        'article_images' => ArticleImageImporter::class,
        'article_reviews' => ArticleReviewImporter::class,
        'publishing_schedules' => PublishingScheduleImporter::class,
        'reporter_assignments' => ReporterAssignmentImporter::class,
        'subscriptions' => SubscriptionImporter::class,
        'payments' => PaymentImporter::class,
        'notifications' => NotificationImporter::class,
        'advertisements' => AdvertisementImporter::class,
        'article_daily_views' => ArticleDailyViewImporter::class,
        'ai_analyses' => AiAnalysisImporter::class,
        'plagiarism_checks' => PlagiarismCheckImporter::class,
    ];

    /** Tables that must exist in a Django database for it to be importable at all. */
    private const CORE_TABLES = ['django_migrations', 'accounts_user', 'categories_category', 'categories_subcategory', 'categories_tag', 'articles_article'];

    private ImportReport $report;

    private LegacyReader $reader;

    /** @var callable */
    private $out;

    public function __construct(?LegacyReader $reader = null, ?ImportReport $report = null, ?callable $out = null)
    {
        $this->reader = $reader ?? new LegacyReader;
        $this->report = $report ?? new ImportReport;
        $this->out = $out ?? fn (string $m) => null;
    }

    public function report(): ImportReport
    {
        return $this->report;
    }

    private function say(string $m): void
    {
        ($this->out)($m);
    }

    /**
     * @param  array{dry_run?: bool, rehearse?: bool, only?: string[], chunk?: int, allow_non_empty?: bool, resolve_defaults?: bool, skip_search_index?: bool}  $o
     */
    public function run(array $o): int
    {
        $o += ['dry_run' => false, 'rehearse' => false, 'only' => [], 'chunk' => 500, 'allow_non_empty' => false, 'resolve_defaults' => false, 'skip_search_index' => false];
        $started = microtime(true);
        $mode = $o['dry_run'] ? 'dry-run' : ($o['rehearse'] ? 'rehearse' : 'import');
        $r = $this->report;
        $r->set('mode', $mode);
        $r->set('started_at', now()->toIso8601String());
        $r->set('options', $o);
        $r->set('status', 'running');
        $rehearsing = false;
        TargetSchema::flush();
        Collation::flush();
        $baseLevel = DB::transactionLevel();
        $exit = 0;

        try {
            $entities = $this->selectEntities($o['only']);

            // ---- guards ----------------------------------------------------------------
            $r->phase('guards');
            $this->guardConfig();
            $this->reader->open();
            $r->set('read_only_verified', $this->reader->assertReadOnly());
            $this->guardDifferentDatabases();
            $this->guardSchemas($entities);
            $targetEmpty = $this->guardTarget($entities, $o['allow_non_empty']);
            $r->endPhase('guards');
            $r->set('source', ['database' => $this->reader->identity()['database'], 'engine' => 'PostgreSQL', 'version' => $this->sourceVersion(), 'driver' => 'pgsql', 'read_only' => true]);
            $r->set('target', ['database' => DB::connection()->getDatabaseName()] + TargetSchema::engine());

            // ---- pre-import validation -----------------------------------------------------
            $this->say('Pre-import validation (read-only) ...');
            $r->phase('pre_import_validation');
            $plan = (new PreImportValidator($this->reader, $r))->run($entities, ! $targetEmpty);
            $this->intentionallyNotImported();
            $r->endPhase('pre_import_validation');
            $blocking = $r->blockingCount();
            $this->say(sprintf('  findings: %d blocking, %d warning, %d info', $blocking, $r->count('warning'), $r->count('info')));

            if ($blocking > 0 && ! $o['resolve_defaults']) {
                $r->set('status', 'blocked');
                $r->error("{$blocking} blocking finding(s). Nothing was written. Review the report, then re-run with --resolve-defaults to apply the documented deterministic resolutions.");
                $this->say("BLOCKED: {$blocking} blocking finding(s); nothing written. Use --resolve-defaults to accept the default resolutions.");
                $exit = 2;

                return $exit;
            }
            if ($blocking > 0) {
                $this->say("  --resolve-defaults given: {$blocking} blocking finding(s) will be resolved with the documented defaults.");
            }

            // ---- import ------------------------------------------------------------------
            $dry = $o['dry_run'];
            if ($o['rehearse'] && ! $dry) {
                DB::beginTransaction();
                $rehearsing = true;
            }
            TargetSchema::$truncatedAtWrite = 0;
            $ctx = new ImportContext($this->reader, $r, $plan, $dry, max(1, (int) $o['chunk']), $targetEmpty);
            foreach ($entities as $name) {
                $imp = new (self::REGISTRY[$name]);
                $this->say(($dry ? 'Planning ' : 'Importing ').$name.' ...');
                $r->phase("import.{$name}");
                $imp->run($ctx);
                $imp->afterRun($ctx);
                $r->endPhase("import.{$name}");
                $e = $r->entity($name);
                $this->say(sprintf('  %s: source %d, imported %d (inserted %d, updated %d), skipped %d', $name, $e['source_count'], $e['imported'], $e['inserted'], $e['updated'], $e['skipped']));
            }

            $this->reportTruncations($dry);

            if (! $dry) {
                if (! $rehearsing) {
                    // DDL (implicit commit): only after every entity transaction has finished.
                    $r->phase('sequence_reset');
                    (new SequenceResetter)->reset($r);
                    $r->endPhase('sequence_reset');
                    $this->say('AUTO_INCREMENT counters reset.');
                }
                // After the entity transactions (in a rehearsal: inside the rollback transaction, proving the indexer works).
                if (! $o['skip_search_index'] && in_array('articles', $entities, true)) {
                    $this->reindexSearch();
                }
                $this->say('Relationship validation ...');
                $r->phase('relationship_validation');
                (new RelationshipValidator($this->reader, $r))->run($entities, $targetEmpty, ! $rehearsing);
                $r->endPhase('relationship_validation');
                if ($r->validationFailures() > 0) {
                    $exit = 4;
                }
            }
            $r->set('status', match (true) {
                $dry => 'dry-run-ok',
                $rehearsing => $exit === 0 ? 'rehearsal-ok (rolled back)' : 'rehearsal-validation-failed (rolled back)',
                $exit === 0 => 'completed',
                default => 'completed-with-validation-failures',
            });
        } catch (ImportAbort $e) {
            $exit = $e->exitCode;
            $r->set('status', in_array($exit, [3, 10, 11, 12], true) ? 'refused' : 'failed');
            $r->error($e->getMessage(), $e->entity);
            $this->say('ERROR: '.$e->getMessage());
        } catch (\Throwable $e) {
            $exit = 1;
            $r->set('status', 'failed');
            $r->error(ImportAbort::sanitize($e));
            $this->say('ERROR: '.ImportAbort::sanitize($e));
        } finally {
            if ($rehearsing) {
                try {
                    while (DB::transactionLevel() > $baseLevel) {
                        DB::rollBack();
                    }
                } catch (\Throwable) {
                }
            }
            $this->reader->close();
            $r->set('finished_at', now()->toIso8601String());
            $r->set('duration_seconds', round(microtime(true) - $started, 3));
            $r->set('exit_code', $exit);
        }

        return $exit;
    }

    /** @return string[] entity names in import order */
    public function selectEntities(array $only): array
    {
        $only = array_values(array_filter(array_map('trim', $only)));
        if (! $only) {
            return array_keys(self::REGISTRY);
        }
        $unknown = array_diff($only, array_keys(self::REGISTRY));
        if ($unknown) {
            throw new ImportAbort('Unknown entities: '.implode(', ', $unknown).'. Valid: '.implode(', ', array_keys(self::REGISTRY)), 1);
        }

        return array_values(array_filter(array_keys(self::REGISTRY), fn ($n) => in_array($n, $only, true)));
    }

    private function guardConfig(): void
    {
        $l = config('database.connections.'.LegacyReader::CONNECTION);
        $default = (string) config('database.default');
        $t = config('database.connections.'.$default);
        if (! $l || ($l['database'] ?? '') === '') {
            throw new ImportAbort('LEGACY_DB_DATABASE is not set. Required: LEGACY_DB_HOST, LEGACY_DB_PORT, LEGACY_DB_DATABASE, LEGACY_DB_USERNAME, LEGACY_DB_PASSWORD.', 10);
        }
        if (($l['driver'] ?? '') !== 'pgsql') {
            throw new ImportAbort('The legacy (Django) connection must be PostgreSQL (driver pgsql); refusing to read from anything else.', 10);
        }
        if ($default === LegacyReader::CONNECTION) {
            throw new ImportAbort('Refusing to run: the default (target) connection is the legacy connection. The importer never writes to the Django database.', 12);
        }
        if (! $t || ! in_array($t['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            throw new ImportAbort('The target (DB_CONNECTION) must be MySQL or MariaDB; got "'.($t['driver'] ?? $default).'".', 12);
        }
        // Different engines can never be the same database; only identical drivers are compared (host/port/database).
        if (DatabaseIdentity::same($l, $t)) {
            throw new ImportAbort('Refusing to run: the legacy (source) and target database are the same ('.$l['database'].'). The importer never writes to the Django database.', 12);
        }
    }

    private function guardDifferentDatabases(): void
    {
        $srcDriver = (string) config('database.connections.'.LegacyReader::CONNECTION.'.driver');
        $dstDriver = DB::connection()->getDriverName();
        if (! DatabaseIdentity::sameEngineFamily($srcDriver, $dstDriver)) {
            return; // PostgreSQL source vs MySQL/MariaDB target: physically different servers, nothing to compare
        }
        // Same engine family (never the case with a supported target, kept as defence in depth): compare what the servers report.
        $src = $this->reader->identity();
        $dst = DB::connection()->selectOne('SELECT DATABASE() AS db, @@port AS port, @@hostname AS host');
        if ($src['database'] === $dst->db && (string) $src['port'] === (string) $dst->port) {
            throw new ImportAbort('Refusing to run: the legacy (source) and target connections point at the same database ('.$dst->db.').', 12);
        }
    }

    private function guardSchemas(array $entities): void
    {
        foreach (self::CORE_TABLES as $t) {
            if (! $this->reader->hasTable($t)) {
                throw new ImportAbort("The legacy database does not look like the Django portal database (table '{$t}' missing). Run the Django migrations on it first; this importer cannot and will not alter it.", 10);
            }
        }
        if (DB::connection()->getSchemaBuilder()->hasTable('django_migrations') || ! DB::connection()->getSchemaBuilder()->hasTable('users')) {
            throw new ImportAbort('The target does not look like a migrated Laravel database (run `php artisan migrate` on it first, and never point DB_* at the Django database).', 12);
        }
        $problems = [];
        foreach ($entities as $name) {
            /** @var AbstractEntityImporter $imp */
            $imp = new (self::REGISTRY[$name]);
            foreach ($imp->requiredColumns() as $table => $cols) {
                if (! $this->reader->hasTable($table)) {
                    if (! $imp->optional() && $table === $imp->sourceTable()) {
                        $problems[] = "table {$table} missing";
                    }

                    continue;
                }
                $have = $this->reader->columns($table);
                foreach (array_diff($cols, $have) as $c) {
                    $problems[] = "{$table}.{$c} missing";
                }
            }
        }
        if ($problems) {
            throw new ImportAbort('The legacy schema is not the expected Django schema ('.implode('; ', array_slice($problems, 0, 12)).'). Apply all Django migrations to the legacy database first (the importer will not).', 10);
        }
    }

    /** @return bool true when every selected target table is empty */
    private function guardTarget(array $entities, bool $allowNonEmpty): bool
    {
        $filled = [];
        foreach ($entities as $name) {
            $table = ImportContext::TARGET_TABLES[$name];
            $n = DB::table($table)->count();
            if ($n > 0) {
                $filled[$table] = $n;
            }
        }
        if (! $filled) {
            return true;
        }
        if (! $allowNonEmpty) {
            $list = implode(', ', array_map(fn ($t, $n) => "{$t} ({$n})", array_keys($filled), $filled));
            throw new ImportAbort("Refusing to import into a non-empty target: {$list}. Use an empty migrated database, or pass --allow-non-empty to upsert idempotently by source id (this OVERWRITES rows with the same ids).", 3);
        }
        $this->report->stat('target_non_empty_tables', $filled);

        return false;
    }

    private function sourceVersion(): ?string
    {
        try {
            return (string) $this->reader->scalar('SHOW server_version');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Values cut to fit a column that the validator did not announce (LEN-TRUNCATED) must never go unnoticed. */
    private function reportTruncations(bool $dry): void
    {
        $announced = count(array_filter($this->report->findings(), fn ($f) => $f['rule'] === 'LEN-TRUNCATED'));
        $n = TargetSchema::$truncatedAtWrite;
        $this->report->stat('values_truncated_to_column_limit', $n);
        if ($n > $announced) {
            $this->report->finding('(various)', null, 'LEN-TRUNCATED-UNANNOUNCED', 'warning', 'values were cut to the column limit while writing', ($n - $announced).' value(s) exceeded their target column but were not announced by the pre-import validation (derived values such as generated aliases)');
        }
    }

    private function reindexSearch(): void
    {
        $this->report->phase('search_index');
        if (class_exists(ArticleSearchIndexer::class)) {
            $n = app(ArticleSearchIndexer::class)->reindexAll();
            $this->report->stat('search_index_rebuilt_articles', $n);
            $this->say("Search index rebuilt for {$n} article(s).");
        } else {
            $this->report->stat('search_index_rebuilt_articles', 'skipped - ArticleSearchIndexer not available; run articles:reindex-search');
        }
        $this->report->endPhase('search_index');
    }

    private function intentionallyNotImported(): void
    {
        $r = $this->report;
        $reader = $this->reader;
        $cnt = fn (string $t) => $reader->hasTable($t) ? $reader->count($t) : null;
        $r->notImported('auth_group', 'Django Groups are not rebuilt: authorisation in Laravel is role based (ADMIN/REPORTER/USER/SUBSCRIBER).', $cnt('auth_group'));
        $r->notImported('auth_group_permissions', 'Django permission rows are not rebuilt (see auth_group).', $cnt('auth_group_permissions'));
        $r->notImported('auth_permission', 'Django model permissions do not exist in Laravel.', $cnt('auth_permission'));
        $r->notImported('accounts_user_groups', 'User-to-group links are not rebuilt (roles come from accounts_user.role / is_superuser).', $cnt('accounts_user_groups'));
        $r->notImported('accounts_user_user_permissions', 'Per-user Django permissions are not rebuilt.', $cnt('accounts_user_user_permissions'));
        $r->notImported('accounts_user.is_staff / is_superuser flags', 'Not stored in Laravel; is_superuser is mapped to role ADMIN, is_staff only gated Django Admin.', null);
        $r->notImported('token_blacklist_outstandingtoken', 'JWT tokens are signed with the Django secret and are not portable; users log in again (Sanctum tokens).', $cnt('token_blacklist_outstandingtoken'));
        $r->notImported('token_blacklist_blacklistedtoken', 'Blacklisted JWTs are meaningless without the outstanding tokens.', $cnt('token_blacklist_blacklistedtoken'));
        $valid = $reader->hasTable('subscriptions_phoneotp') ? (int) $reader->scalar('SELECT count(*) FROM subscriptions_phoneotp WHERE NOT is_verified AND expires_at > now()') : 0;
        $r->notImported('subscriptions_phoneotp', "OTP rows are short-lived and hashed with Django's password hasher, which the Laravel OTP service (HMAC) cannot verify; {$valid} still-valid OTP(s) are dropped, users request a new code.", $cnt('subscriptions_phoneotp'));
        $r->notImported('django_session', 'Django sessions are not used by the API.', $cnt('django_session'));
        $r->notImported('django_admin_log', 'Django Admin audit log is not part of the new system.', $cnt('django_admin_log'));
        $r->notImported('django_content_type / django_migrations', 'Django framework bookkeeping.', $cnt('django_content_type'));
        $r->notImported('media_mediametadata (table)', 'Folded into article_images.metadata (JSON) - not lost.', $cnt('media_mediametadata'));
        $r->notImported('articles_article.search_vector', 'Rebuilt by ArticleSearchIndexer after the import.', null);
        $r->notImported('ai_aianalysisresult.raw_response / ai_plagiarismcheckresult.raw_response', 'Full provider payloads (may embed article text); the target keeps only the structured result columns.', null);
        $r->notImported('advertisements_advertisement.creative_type', 'Always IMAGE; the column does not exist in the target.', null);
        $r->notImported('accounts_user.password (unsupported / unusable hashes)', 'Only pbkdf2_sha256 and bcrypt hashes can be verified; any other hash is replaced by an unusable random hash (see USR-PASSWORD-UNSUPPORTED findings) - the user resets the password.', null);
    }
}
