<?php

namespace App\Console\Commands;

use App\Providers\OpsServiceProvider;
use App\Services\Analytics\ViewRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Read-only deployment diagnostics (PHP, MySQL/MariaDB, drivers, scheduler, queue, storage, config sanity).
 * Prints NO secrets (integrations are reported as set/unset only). Exit code 1 when any check FAILs
 * (or WARNs with --strict). Note: it inspects the PHP CLI configuration; the web SAPI may use another php.ini.
 * The only writes are throw-away probes (a cache round-trip key, a temp file in storage/), removed at once.
 */
class PortalDoctor extends Command
{
    protected $signature = 'portal:doctor {--json : Machine-readable output} {--strict : Treat warnings as failures}';

    protected $description = 'Read-only deployment diagnostics (PHP, database, cache/queue/analytics drivers, scheduler, storage, config)';

    private const OK = 'OK';

    private const INFO = 'INFO';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /** @var list<array{section:string,status:string,check:string,detail:string}> */
    private array $results = [];

    private string $section = '';

    /** Heartbeat older than this means cron / schedule:run is not firing. */
    private const HEARTBEAT_MAX_AGE_SECONDS = 300;

    public function handle(): int
    {
        $this->results = [];   // the command object is reused when called repeatedly in one process
        $this->php();
        $dbOk = $this->database();
        $this->drivers($dbOk);
        $this->scheduler();
        $this->queueState($dbOk);
        $this->storage();
        $this->application();
        $this->integrations();

        $fails = collect($this->results)->where('status', self::FAIL)->count();
        $warns = collect($this->results)->where('status', self::WARN)->count();
        $failed = $fails > 0 || ($this->option('strict') && $warns > 0);

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => ! $failed, 'failures' => $fails, 'warnings' => $warns, 'checks' => $this->results], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $section = null;
        foreach ($this->results as $r) {
            if ($r['section'] !== $section) {
                $section = $r['section'];
                $this->newLine();
                $this->line("== {$section}");
            }
            $this->line(sprintf('[%-4s] %s: %s', $r['status'], $r['check'], $r['detail']));
        }
        $this->newLine();
        $this->line("Result: {$fails} failure(s), {$warns} warning(s). ".($failed ? 'NOT READY.' : 'No blocking problems.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $status, string $check, string $detail): void
    {
        $this->results[] = ['section' => $this->section, 'status' => $status, 'check' => $check, 'detail' => $detail];
    }

    // ------------------------------------------------------------------ PHP

    private function php(): void
    {
        $this->section = 'PHP (CLI)';
        $this->add(version_compare(PHP_VERSION, '8.4.1', '>=') ? self::OK : self::FAIL, 'version', PHP_VERSION.(version_compare(PHP_VERSION, '8.4.1', '>=') ? '' : ' (8.4.1+ required by the locked dependencies)'));

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo', 'ctype', 'tokenizer', 'curl'] as $ext) {
            $this->add(extension_loaded($ext) ? self::OK : self::FAIL, "ext-{$ext}", extension_loaded($ext) ? 'loaded' : 'MISSING');
        }
        if (extension_loaded('gd')) {
            $gd = gd_info();
            $webp = ! empty($gd['WebP Support']);
            $this->add($webp ? self::OK : self::FAIL, 'ext-gd', $webp ? 'loaded, WebP + JPEG + PNG support' : 'loaded but WITHOUT WebP support (WebP uploads cannot be decoded)');
            foreach (['JPEG Support', 'PNG Support'] as $feature) {
                if (empty($gd[$feature])) {
                    $this->add(self::FAIL, "gd {$feature}", 'MISSING');
                }
            }
        } else {
            $this->add(self::FAIL, 'ext-gd', 'MISSING (image uploads need it)');
        }
        $this->add(function_exists('exif_read_data') ? self::OK : self::WARN, 'ext-exif', function_exists('exif_read_data') ? 'loaded' : 'missing: JPEG orientation is not corrected on upload');

        $needsRedis = $this->redisSelected();
        $client = (string) config('database.redis.client', 'phpredis');
        if (extension_loaded('redis')) {
            $this->add(self::OK, 'ext-redis', 'loaded (only used when a Redis driver is selected)');
        } elseif ($needsRedis && $client === 'phpredis') {
            $this->add(self::FAIL, 'ext-redis', 'MISSING but a Redis driver is selected (REDIS_CLIENT=phpredis)');
        } else {
            $this->add(self::INFO, 'ext-redis', 'not loaded (optional; not needed with database/file drivers)');
        }

        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $proc = function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
        $this->add($proc ? self::OK : self::FAIL, 'proc_open', $proc ? 'available (schedule:run starts artisan commands with it)' : 'DISABLED: schedule:run cannot start scheduled commands; call each command from its own cron line instead');

        $ini = [
            'upload_max_filesize' => ['need' => 6, 'label' => '6M'],
            'post_max_size' => ['need' => 12, 'label' => '12M'],
        ];
        foreach ($ini as $key => $meta) {
            $bytes = $this->bytes((string) ini_get($key));
            $this->add($bytes >= $meta['need'] * 1048576 ? self::OK : self::WARN, $key, ini_get($key).' (needs >= '.$meta['label'].'; CLI value, verify the web PHP configuration)');
        }
        $mem = $this->bytes((string) ini_get('memory_limit'));
        $this->add($mem === -1 || $mem >= 256 * 1048576 ? self::OK : self::WARN, 'memory_limit', ini_get('memory_limit').' (image processing wants >= 256M; CLI value, verify the web PHP configuration)');
        $this->add(self::INFO, 'max_execution_time', ini_get('max_execution_time').'s (CLI is normally 0 = unlimited; the web value is set in hPanel and may be much lower)');
        $this->add(self::INFO, 'opcache', function_exists('opcache_get_status') && ini_get('opcache.enable_cli') !== '' ? 'available' : 'not available');
    }

    // ------------------------------------------------------------------ database

    private function database(): bool
    {
        $this->section = 'Database';
        try {
            $row = DB::selectOne('SELECT VERSION() AS v, @@session.sql_mode AS sm, @@session.time_zone AS tz, @@collation_database AS cd, @@collation_connection AS cc, @@max_allowed_packet AS pk, @@global.time_zone AS gtz, NOW() AS now_db');
        } catch (\Throwable $e) {
            $this->add(self::FAIL, 'connectivity', 'cannot connect / query: '.$this->safeMessage($e));

            return false;
        }
        $this->add(self::OK, 'connectivity', 'connected to database "'.DB::connection()->getDatabaseName().'"');

        $version = (string) $row->v;
        $maria = stripos($version, 'mariadb') !== false;
        preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m);
        $num = isset($m[1]) ? sprintf('%d.%d.%d', $m[1], $m[2], $m[3]) : '0.0.0';
        $min = $maria ? '10.3.0' : '5.7.0';
        $tested = $maria ? '10.6.0' : '8.0.0';
        if (version_compare($num, $min, '<')) {
            $status = self::FAIL;
            $note = 'too old (need MariaDB 10.3+ / MySQL 5.7+ for generated columns, InnoDB FULLTEXT and JSON functions)';
        } elseif (version_compare($num, $tested, '<')) {
            $status = self::WARN;
            $note = 'older than the tested range (MariaDB 10.11 / MySQL 8)';
        } else {
            $status = self::OK;
            $note = 'supported';
        }
        $this->add($status, 'server version', ($maria ? 'MariaDB ' : 'MySQL ').$num.' - '.$note);

        // FULLTEXT search index
        try {
            $schema = DB::getDatabaseName();
            $idx = DB::select("SELECT DISTINCT index_name AS idx FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'article_search_index' AND index_type = 'FULLTEXT'", [$schema]);
            $engine = DB::selectOne('SELECT engine AS engine_name FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$schema, 'article_search_index'])?->engine_name;
            if (! Schema::hasTable('article_search_index')) {
                $this->add(self::FAIL, 'FULLTEXT search', 'table article_search_index missing (run php artisan migrate --force)');
            } elseif ($idx === [] || strcasecmp((string) $engine, 'InnoDB') !== 0) {
                $this->add(self::FAIL, 'FULLTEXT search', 'article_search_index has no InnoDB FULLTEXT index (engine: '.($engine ?: '?').')');
            } else {
                $this->add(self::OK, 'FULLTEXT search', count($idx).' FULLTEXT index(es) on InnoDB table article_search_index');
            }
        } catch (\Throwable $e) {
            $this->add(self::WARN, 'FULLTEXT search', 'could not inspect: '.$this->safeMessage($e));
        }
        try {
            $tok = DB::selectOne("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'");
            $val = $tok ? (int) ($tok->Value ?? $tok->value ?? 0) : 0;
            $cfg = (int) config('portal.search.min_token_size', 3);
            $this->add($val === $cfg ? self::OK : self::WARN, 'innodb_ft_min_token_size', ($val ?: 'unknown').($val === $cfg ? '' : " (app assumes {$cfg}: set SEARCH_MIN_TOKEN_SIZE={$val}; shared hosting usually cannot change the server value)"));
        } catch (\Throwable $e) {
            $this->add(self::WARN, 'innodb_ft_min_token_size', 'could not read: '.$this->safeMessage($e));
        }

        $this->add(self::INFO, 'sql_mode (session)', $row->sm ?: '(empty)');
        $this->add(str_starts_with((string) $row->cd, 'utf8mb4') ? self::OK : self::WARN, 'database collation', $row->cd.' / connection '.$row->cc.(str_starts_with((string) $row->cd, 'utf8mb4') ? '' : ' (utf8mb4 recommended for the whole database)'));

        // DATETIME columns hold app-timezone wall-clock values: session NOW() must equal the app clock.
        $app = Carbon::now(config('app.timezone'));
        $db = Carbon::parse((string) $row->now_db, config('app.timezone'));
        $drift = abs($app->getTimestamp() - $db->getTimestamp());
        $this->add($drift <= 5 ? self::OK : self::FAIL, 'time_zone', "session {$row->tz} (server global {$row->gtz}), app ".config('app.timezone').'; clock difference '.$drift.'s'.($drift <= 5 ? '' : ': DATETIME values would be mis-timed, set DB_TIMEZONE to the numeric app offset'));

        $pk = (int) $row->pk;
        // Worst legal article: 2M-char body + 1M-char excerpt of 4-byte characters = ~12 MB in one INSERT.
        $this->add($pk >= 16 * 1048576 ? self::OK : self::WARN, 'max_allowed_packet', round($pk / 1048576, 1).' MB'.($pk >= 16 * 1048576 ? '' : ($pk >= 4 * 1048576
            ? ' (enough for normal articles; an emoji-heavy article near the 2M-character limit needs ~12 MB, >= 16 MB recommended)'
            : ' (low: large article bodies / queue payloads may fail; >= 16 MB recommended)')));

        $this->migrations();

        return true;
    }

    private function migrations(): void
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                $this->add(self::FAIL, 'migrations', 'migrations table missing: run php artisan migrate --force');

                return;
            }
            $files = array_keys($migrator->getMigrationFiles($migrator->paths() ?: [database_path('migrations')]));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff($files, $ran));
            $this->add($pending === [] ? self::OK : self::FAIL, 'migrations', $pending === [] ? count($ran).' applied, none pending' : count($pending).' pending (first: '.$pending[0].'): run php artisan migrate --force');
        } catch (\Throwable $e) {
            $this->add(self::FAIL, 'migrations', 'could not determine state: '.$this->safeMessage($e));
        }
    }

    // ------------------------------------------------------------------ drivers

    private function drivers(bool $dbOk): void
    {
        $this->section = 'Drivers';
        $store = (string) config('cache.default');
        $this->add(self::INFO, 'CACHE_STORE', $store);
        $this->probeCacheStore($store, $dbOk, 'default cache store');
        if ($limiter = config('cache.limiter')) {
            $this->add(self::INFO, 'CACHE_LIMITER_STORE', (string) $limiter);
            $this->probeCacheStore((string) $limiter, $dbOk, 'rate-limiter store');
        }

        $queue = (string) config('queue.default');
        $this->add(self::INFO, 'QUEUE_CONNECTION', $queue);
        $driver = config("queue.connections.{$queue}.driver");
        if ($driver === 'sync') {
            $this->add(self::WARN, 'queue', 'sync: jobs (WhatsApp, e-mail, search reindex, subscriber notifications) run INSIDE the web request; no retries and no failed_jobs. Fine for a very small site, otherwise use database.');
        } elseif ($driver === 'database') {
            $table = (string) config("queue.connections.{$queue}.table", 'jobs');
            $failed = (string) config('queue.failed.table', 'failed_jobs');
            if (! $dbOk) {
                $this->add(self::FAIL, 'queue tables', 'database unavailable');
            } else {
                foreach ([$table, $failed] as $t) {
                    $this->add(Schema::hasTable($t) ? self::OK : self::FAIL, "table {$t}", Schema::hasTable($t) ? 'present' : 'MISSING (php artisan migrate --force)');
                }
            }
        } elseif ($driver === 'redis') {
            $this->probeRedis((string) config("queue.connections.{$queue}.connection", 'default'), 'queue Redis connection');
        } else {
            $this->add(self::WARN, 'queue', "driver [{$driver}] is not covered by this tool");
        }

        try {
            $analytics = ViewRecorder::driver();
        } catch (\Throwable $e) {
            $this->add(self::FAIL, 'ANALYTICS_DRIVER', $e->getMessage());

            return;
        }
        $this->add(self::INFO, 'ANALYTICS_DRIVER', $analytics);
        if ($analytics === 'redis') {
            $this->probeRedis('analytics', 'analytics Redis connection');
        } elseif ($dbOk) {
            $this->add(Schema::hasTable('article_daily_views') ? self::OK : self::FAIL, 'table article_daily_views', Schema::hasTable('article_daily_views') ? 'present (views are counted here directly; dedupe uses the cache store)' : 'MISSING');
            if (config("cache.stores.{$store}.driver") === 'array' && ! app()->environment('testing')) {
                $this->add(self::WARN, 'analytics dedupe', 'cache store "array" is per-process: the view cooldown does not dedupe across requests');
            }
        }
    }

    private function probeCacheStore(string $store, bool $dbOk, string $label): void
    {
        $cfg = config("cache.stores.{$store}");
        if (! $cfg) {
            $this->add(self::FAIL, $label, "store [{$store}] is not defined in config/cache.php");

            return;
        }
        $driver = $cfg['driver'] ?? '?';
        if ($driver === 'array' && ! app()->environment('testing')) {
            $this->add(self::WARN, $label, 'array store is per-process: rate limits, view dedupe, locks and the scheduler heartbeat do not persist between requests');
        }
        if ($driver === 'database') {
            if (! $dbOk) {
                $this->add(self::FAIL, $label, 'database store selected but the database is unreachable');

                return;
            }
            $tables = [$cfg['table'] ?? 'cache', $cfg['lock_table'] ?? 'cache_locks'];
            foreach ($tables as $t) {
                if (! Schema::hasTable($t)) {
                    $this->add(self::FAIL, $label, "table {$t} missing (php artisan migrate --force)");

                    return;
                }
            }
        }
        if ($driver === 'redis' && ! $this->probeRedis((string) ($cfg['connection'] ?? 'cache'), "{$label} Redis connection")) {
            return;
        }

        $key = 'portal:doctor:'.Str::random(12);
        try {
            $repo = Cache::store($store);
            $added = $repo->add($key, 'ok', 10);
            $again = $repo->add($key, 'x', 10);
            $read = $repo->get($key);
            $repo->put($key.':n', 1, 10);
            $inc = $repo->increment($key.':n');
            $repo->forget($key);
            $repo->forget($key.':n');
            $good = $added === true && $again === false && $read === 'ok' && $inc === 2;
            $this->add($good ? self::OK : self::FAIL, $label, "{$driver} round-trip (add/get/increment/forget) ".($good ? 'works' : 'returned unexpected results'));
        } catch (\Throwable $e) {
            $this->add(self::FAIL, $label, "{$driver} round-trip failed: ".$this->safeMessage($e));
        }
    }

    private function probeRedis(string $connection, string $label): bool
    {
        if (! extension_loaded('redis') && config('database.redis.client', 'phpredis') === 'phpredis') {
            $this->add(self::FAIL, $label, 'phpredis extension not loaded');

            return false;
        }
        try {
            Redis::connection($connection)->ping();
            $this->add(self::OK, $label, "connection [{$connection}] answers PING");

            return true;
        } catch (\Throwable $e) {
            $this->add(self::FAIL, $label, "connection [{$connection}] unreachable: ".$this->safeMessage($e));

            return false;
        }
    }

    private function redisSelected(): bool
    {
        $stores = array_filter([config('cache.default'), config('cache.limiter')]);
        foreach ($stores as $s) {
            if ((config("cache.stores.{$s}.driver")) === 'redis') {
                return true;
            }
        }
        if (config('queue.connections.'.config('queue.default').'.driver') === 'redis') {
            return true;
        }

        return strtolower((string) config('portal.analytics.driver')) === 'redis';
    }

    // ------------------------------------------------------------------ scheduler / queue

    private function scheduler(): void
    {
        $this->section = 'Scheduler';
        $store = (string) config('cache.default');
        if (config("cache.stores.{$store}.driver") === 'array' && ! app()->environment('testing')) {
            $this->add(self::WARN, 'heartbeat', 'cannot be recorded with the array cache store');

            return;
        }
        try {
            $beat = Cache::get(OpsServiceProvider::HEARTBEAT_KEY);
        } catch (\Throwable $e) {
            $this->add(self::WARN, 'heartbeat', 'cache unreadable: '.$this->safeMessage($e));

            return;
        }
        if (! $beat) {
            $this->add(self::WARN, 'heartbeat', 'no scheduler tick recorded yet. Add the cron entry (* * * * * cd <app> && php artisan schedule:run) and re-run this command after 1-2 minutes.');

            return;
        }
        $age = (int) Carbon::parse((string) $beat)->diffInSeconds(now(), true);
        $this->add($age <= self::HEARTBEAT_MAX_AGE_SECONDS ? self::OK : self::FAIL, 'heartbeat', "last schedule:run tick {$age}s ago".($age <= self::HEARTBEAT_MAX_AGE_SECONDS ? '' : ' (cron is not running every minute: scheduled publishing, reminders and notifications are stalled)'));
    }

    private function queueState(bool $dbOk): void
    {
        $this->section = 'Queue';
        $queue = (string) config('queue.default');
        $driver = config("queue.connections.{$queue}.driver");
        $this->add(self::INFO, 'worker mode', config('portal.queue_via_scheduler')
            ? 'QUEUE_VIA_SCHEDULER=true: the scheduler runs queue:work every minute (no daemon)'
            : 'QUEUE_VIA_SCHEDULER=false: a queue:work daemon (supervisor) or a cron-run queue:work is required for '.$driver.' queues');
        if ($driver === 'database' && $dbOk) {
            $table = (string) config("queue.connections.{$queue}.table", 'jobs');
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            try {
                if (Schema::hasTable($table)) {
                    $pending = (int) DB::table($table)->count();
                    $oldest = DB::table($table)->whereNull('reserved_at')->min('available_at');
                    $age = $oldest ? max(0, time() - (int) $oldest) : 0;
                    $status = $age > 600 ? self::FAIL : ($age > 180 ? self::WARN : self::OK);
                    $this->add($status, 'backlog', "{$pending} job(s) waiting".($pending ? ", oldest runnable for {$age}s" : '').($status === self::OK ? '' : ' - nothing is draining the queue (no worker and QUEUE_VIA_SCHEDULER is off, or cron is not running)'));
                }
                if (Schema::hasTable($failedTable)) {
                    $failed = (int) DB::table($failedTable)->count();
                    $this->add($failed === 0 ? self::OK : self::WARN, 'failed jobs', "{$failed} in {$failedTable}".($failed ? ' (php artisan queue:failed; retry with queue:retry all)' : ''));
                }
            } catch (\Throwable $e) {
                $this->add(self::WARN, 'backlog', 'could not inspect: '.$this->safeMessage($e));
            }
        }
    }

    // ------------------------------------------------------------------ storage / application / integrations

    private function storage(): void
    {
        $this->section = 'Storage';
        foreach (['storage', 'storage/logs', 'storage/framework/cache', 'storage/framework/views', 'bootstrap/cache'] as $rel) {
            $path = base_path($rel);
            $ok = is_dir($path) && is_writable($path);
            if ($ok) {
                $probe = @tempnam($path, 'doctor');
                $ok = $probe !== false && @file_put_contents($probe, 'x') !== false;
                if ($probe !== false) {
                    @unlink($probe);
                }
            }
            $this->add($ok ? self::OK : self::FAIL, $rel, $ok ? 'writable' : 'NOT writable (chmod 775 / correct owner)');
        }
        $this->add(self::INFO, 'public/storage symlink', 'not needed: uploads go to Bunny.net, nothing is served from storage/');
    }

    private function application(): void
    {
        $this->section = 'Application';
        $key = (string) config('app.key');
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $keyOk = $key !== '' && is_string($raw) && in_array(strlen($raw), [16, 32], true);
        $this->add($keyOk ? self::OK : self::FAIL, 'APP_KEY', $keyOk ? 'set (valid length)' : ($key === '' ? 'NOT SET: php artisan key:generate' : 'invalid length'));

        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $this->add(self::INFO, 'APP_ENV', $env);
        if ($debug) {
            $this->add($env === 'production' ? self::FAIL : self::WARN, 'APP_DEBUG', 'true (exposes stack traces and configuration; must be false in production)');
        } else {
            $this->add(self::OK, 'APP_DEBUG', 'false');
        }
        if (! in_array($env, ['production', 'testing'], true)) {
            $this->add(self::WARN, 'APP_ENV', "\"{$env}\": set APP_ENV=production on the live site");
        }

        $url = (string) config('app.url');
        $placeholder = $url === '' || preg_match('#(localhost|127\.0\.0\.1|example\.(com|org))#i', $url);
        $this->add($placeholder ? self::WARN : self::OK, 'APP_URL', $placeholder ? 'looks like a local/placeholder URL' : 'set');

        $front = (string) config('portal.frontend_url');
        $origins = (array) config('cors.allowed_origins');
        $frontPlaceholder = $front === '' || preg_match('#(localhost|127\.0\.0\.1|example\.(com|org))#i', $front);
        $this->add($frontPlaceholder || in_array('*', $origins, true) ? self::WARN : self::OK, 'FRONTEND_URL / CORS', $frontPlaceholder ? 'FRONTEND_URL is a placeholder' : (in_array('*', $origins, true) ? 'CORS allows any origin' : count($origins).' allowed origin(s)'));

        $this->add(app()->configurationIsCached() ? self::OK : self::INFO, 'config cache', app()->configurationIsCached() ? 'cached' : 'not cached (php artisan config:cache is recommended in production; env() is only read from .env until then)');

        $mailer = (string) config('mail.default');
        $badMailer = in_array($mailer, ['log', 'array'], true);
        $this->add($badMailer && $env === 'production' ? self::WARN : self::INFO, 'MAIL_MAILER', $mailer.($badMailer ? ' (mail is not really sent)' : (config('mail.mailers.smtp.host') || $mailer !== 'smtp' ? '' : ' (MAIL_HOST unset)')));

        $this->add(self::INFO, 'timezone', (string) config('app.timezone'));
    }

    private function integrations(): void
    {
        $this->section = 'Integrations (set/unset only)';
        $groups = [
            'Bunny.net storage' => ['portal.bunny.storage_zone', 'portal.bunny.api_key', 'portal.bunny.pull_zone_url'],
            'Razorpay' => ['portal.razorpay.key_id', 'portal.razorpay.key_secret', 'portal.razorpay.webhook_secret'],
            'WATI (WhatsApp)' => ['portal.wati.endpoint', 'portal.wati.token'],
            'Gemini (AI analysis)' => ['portal.gemini.api_key'],
            'OpenAI' => ['portal.openai.api_key'],
            'Copyleaks' => ['portal.copyleaks.email', 'portal.copyleaks.api_key', 'portal.copyleaks.webhook_secret', 'portal.copyleaks.webhook_base_url'],
        ];
        foreach ($groups as $name => $keys) {
            $set = array_filter($keys, fn ($k) => trim((string) config($k)) !== '');
            if (count($set) === count($keys)) {
                $this->add(self::OK, $name, 'set');
            } elseif ($set === []) {
                $this->add(self::INFO, $name, 'unset (feature unavailable until configured)');
            } else {
                $missing = array_map(fn ($k) => Str::afterLast($k, '.'), array_diff($keys, $set));
                $this->add(self::WARN, $name, 'partially set, missing: '.implode(', ', $missing));
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Exception text without anything credential-shaped (DSNs, passwords, hosts in URLs). */
    private function safeMessage(\Throwable $e): string
    {
        $msg = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';
        $msg = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '<url>', $msg) ?? $msg;
        foreach (['database.connections.'.config('database.default').'.password', 'database.redis.default.password', 'app.key'] as $secretKey) {
            $secret = (string) config($secretKey);
            if ($secret !== '' && strlen($secret) >= 4) {
                $msg = str_replace($secret, '<redacted>', $msg);
            }
        }

        return Str::limit($msg, 220);
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
