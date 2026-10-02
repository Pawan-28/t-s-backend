<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * A second PostgreSQL database (`<DB_DATABASE>_legacy`, on the PostgreSQL server described by settings()) holding the REAL Django schema (generated with pg_dump
 * from a database built by the real Django migrations, foreign keys stripped) plus helpers to insert tiny
 * synthetic legacy rows. The importer reads it through the `legacy_django` connection; the fixture writes to it
 * through its own separate PDO connection.
 */
class LegacyFixture
{
    private static ?PDO $pdo = null;

    private static ?string $dbName = null;

    private static bool $schemaLoaded = false;

    private const TABLES = [
        'accounts_user', 'accounts_user_groups', 'accounts_user_user_permissions', 'advertisements_advertisement', 'ai_aianalysisresult',
        'ai_plagiarismcheckresult', 'analytics_articledailyview', 'articles_article', 'articles_article_tags', 'auth_group', 'auth_group_permissions',
        'auth_permission', 'categories_category', 'categories_industry', 'categories_subcategory', 'categories_tag', 'django_admin_log',
        'django_content_type', 'django_migrations', 'django_session', 'media_articleimage', 'media_mediametadata', 'notifications_notification',
        'reporters_articlereview', 'reporters_publishingschedule', 'reporters_reportercategoryassignment', 'subscriptions_payment',
        'subscriptions_phoneotp', 'subscriptions_subscription', 'subscriptions_subscriptionplan', 'token_blacklist_blacklistedtoken',
        'token_blacklist_outstandingtoken',
    ];

    /**
     * Connection settings of the PostgreSQL server that hosts the legacy fixture databases. The default connection is
     * MySQL/MariaDB, so the server is addressed explicitly: LEGACY_TEST_DB_* env vars, else the LEGACY_DB_* settings of the
     * `legacy_django` connection, else the local dev server (127.0.0.1:5432, user tsl).
     *
     * @return array{host: string, port: string, username: string, password: string, database: string}
     */
    public static function settings(): array
    {
        $legacy = config('database.connections.legacy_django', []);
        $pick = fn (string $env, mixed $fallback) => ($v = env($env)) !== null && $v !== '' ? (string) $v : (string) $fallback;

        return [
            'host' => $pick('LEGACY_TEST_DB_HOST', $legacy['host'] ?? '127.0.0.1'),
            'port' => $pick('LEGACY_TEST_DB_PORT', $legacy['port'] ?? '5432'),
            'username' => $pick('LEGACY_TEST_DB_USERNAME', ($legacy['username'] ?? '') !== '' && $legacy['username'] !== 'postgres' ? $legacy['username'] : 'tsl'),
            'password' => $pick('LEGACY_TEST_DB_PASSWORD', ($legacy['password'] ?? '') !== '' ? $legacy['password'] : 'tsl_local_dev'),
            'database' => $pick('LEGACY_TEST_DB_DATABASE', (config('database.connections.'.config('database.default').'.database') ?: 'tsl_test').'_legacy'),
        ];
    }

    /** Returns the ready fixture, or null when PostgreSQL is unavailable or does not allow creating the fixture database (tests then skip). */
    public static function boot(): ?self
    {
        if (! extension_loaded('pdo_pgsql')) {
            return null;
        }
        try {
            $cfg = self::settings();
            self::$dbName = $cfg['database'];
            $dsn = fn (string $db) => "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$db}";
            $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];

            if (! self::$pdo) {
                $admin = new PDO($dsn('postgres'), $cfg['username'], $cfg['password'], $opts);
                $exists = $admin->query("SELECT 1 FROM pg_database WHERE datname = '".self::$dbName."'")->fetchColumn();
                if (! $exists) {
                    $admin->exec('CREATE DATABASE "'.self::$dbName.'"');
                }
                self::$pdo = new PDO($dsn(self::$dbName), $cfg['username'], $cfg['password'], $opts);
                // Instants are inserted with explicit offsets; reading them back must not depend on the server zone.
                self::$pdo->exec("SET TIME ZONE 'UTC'");
            }
            if (! self::$schemaLoaded) {
                $has = self::$pdo->query("SELECT to_regclass('public.accounts_user')")->fetchColumn();
                if (! $has) {
                    self::$pdo->exec((string) file_get_contents(base_path('tests/Fixtures/import/django_schema.sql')));
                    self::$pdo->exec('SET search_path TO public');
                }
                self::$schemaLoaded = true;
            }
        } catch (\Throwable) {
            self::$pdo = null;

            return null;
        }

        return new self;
    }

    /** Point the `legacy_django` connection at the fixture database and empty every legacy table. */
    public function attach(): void
    {
        $cfg = self::settings();
        config([
            'database.connections.legacy_django.host' => $cfg['host'],
            'database.connections.legacy_django.port' => $cfg['port'],
            'database.connections.legacy_django.database' => self::$dbName,
            'database.connections.legacy_django.username' => $cfg['username'],
            'database.connections.legacy_django.password' => $cfg['password'],
        ]);
        DB::purge('legacy_django');
        self::$pdo->exec('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');
    }

    public function dbName(): string
    {
        return self::$dbName;
    }

    public function pdo(): PDO
    {
        return self::$pdo;
    }

    public function exec(string $sql): void
    {
        self::$pdo->exec($sql);
    }

    /** @return array<int, array<string, mixed>> */
    public function query(string $sql): array
    {
        return self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Insert a row (explicit id supported); returns its id. */
    public function insert(string $table, array $values): int
    {
        $cols = array_keys($values);
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $st = self::$pdo->prepare('INSERT INTO '.$table.' ('.implode(', ', array_map(fn ($c) => '"'.$c.'"', $cols)).") VALUES ({$ph}) RETURNING id");
        $st->execute(array_map(fn ($v) => is_bool($v) ? ($v ? 't' : 'f') : (is_array($v) ? json_encode($v) : $v), array_values($values)));

        return (int) $st->fetchColumn();
    }

    // ------------------------------------------------------------------ typed helpers
    private function ts(): string
    {
        return '2026-01-15 10:00:00+00';
    }

    public function user(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('accounts_user', $o + [
            'password' => 'pbkdf2_sha256$1000$saltsalt$'.base64_encode(hash_pbkdf2('sha256', 'Legacy-Pass-1', 'saltsalt', 1000, 0, true)),
            'is_superuser' => false, 'email' => "legacy{$n}@example.test", 'first_name' => 'Legacy', 'last_name' => 'User',
            'role' => 'USER', 'is_active' => true, 'is_staff' => false, 'created_at' => $this->ts(), 'updated_at' => $this->ts(),
        ]);
    }

    public function industry(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('categories_industry', $o + ['name' => "Industry {$n}", 'slug' => "industry-{$n}", 'description' => '', 'is_active' => true, 'display_order' => 0, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function category(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('categories_category', $o + ['name' => "Category {$n}", 'slug' => "category-{$n}", 'description' => '', 'is_active' => true, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function subcategory(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('categories_subcategory', $o + ['name' => "Sub {$n}", 'slug' => "sub-{$n}", 'description' => '', 'is_active' => true, 'display_order' => 0, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function tag(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('categories_tag', $o + ['name' => "tag {$n}", 'slug' => "tag-{$n}", 'created_at' => $this->ts()]);
    }

    public function article(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('articles_article', $o + [
            'title' => "Legacy article {$n}", 'slug' => "legacy-article-{$n}", 'excerpt' => 'ex', 'content' => "<p>Body {$n}</p>", 'status' => 'PUBLISHED',
            'rejection_reason' => '', 'access_level' => 'PUBLIC', 'published_at' => $this->ts(), 'created_at' => $this->ts(), 'updated_at' => $this->ts(),
            'faqs' => '[]', 'location_name' => '',
        ]);
    }

    public function image(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('media_articleimage', $o + ['bunny_url' => "https://cdn.example.test/{$n}.jpg", 'bunny_storage_path' => "articles/{$n}.jpg", 'alt_text' => '', 'caption' => '', 'is_featured' => false, 'display_order' => 0, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function plan(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('subscriptions_subscriptionplan', $o + ['name' => "Plan {$n}", 'slug' => "plan-{$n}", 'description' => '', 'price_amount' => '199.00', 'price_currency' => 'INR', 'duration_days' => 30, 'is_active' => true, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function subscription(array $o = []): int
    {
        return $this->insert('subscriptions_subscription', $o + ['status' => 'PENDING', 'contact_email' => '', 'contact_phone' => '', 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function payment(array $o = []): int
    {
        static $n = 0;
        $n++;

        return $this->insert('subscriptions_payment', $o + ['razorpay_order_id' => "order_FIXTURE_{$n}", 'razorpay_payment_id' => '', 'razorpay_signature' => '', 'amount' => '199.00', 'currency' => 'INR', 'status' => 'CREATED', 'failure_reason' => '', 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    public function schedule(array $o = []): int
    {
        return $this->insert('reporters_publishingschedule', $o + ['scheduled_for' => '2026-02-01 10:00:00+00', 'status' => 'PENDING', 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
    }

    /** A small consistent legacy dataset. Returns ids. */
    public function baseline(): array
    {
        $admin = $this->user(['email' => 'admin@example.test', 'is_superuser' => true, 'is_staff' => true, 'role' => 'ADMIN']);
        $rep = $this->user(['email' => 'rep@example.test', 'role' => 'REPORTER', 'phone' => '+919000000001']);
        $reader = $this->user(['email' => 'reader@example.test', 'phone' => '+919000000002']);
        $ind = $this->industry();
        $cat = $this->category(['industry_id' => $ind]);
        $sub = $this->subcategory(['category_id' => $cat]);
        $tag = $this->tag();
        $art = $this->article(['author_id' => $rep, 'subcategory_id' => $sub, 'category_id' => $cat]);
        $this->insert('articles_article_tags', ['article_id' => $art, 'tag_id' => $tag]);
        $img = $this->image(['article_id' => $art, 'uploaded_by_id' => $rep, 'is_featured' => true]);
        $this->insert('media_mediametadata', ['image_id' => $img, 'original_filename' => 'a.jpg', 'content_type' => 'image/jpeg', 'file_size_bytes' => 100, 'width' => 10, 'height' => 5, 'checksum' => str_repeat('a', 64), 'created_at' => $this->ts()]);
        $plan = $this->plan();
        $sb = $this->subscription(['user_id' => $reader, 'plan_id' => $plan, 'status' => 'ACTIVE', 'started_at' => $this->ts(), 'expires_at' => '2099-01-01 00:00:00+00']);
        $pay = $this->payment(['subscription_id' => $sb, 'user_id' => $reader, 'razorpay_payment_id' => 'pay_FIXTURE_1', 'razorpay_signature' => 'sig-fixture-secret-value', 'status' => 'PAID']);
        $this->insert('notifications_notification', ['recipient_id' => $rep, 'article_id' => $art, 'notification_type' => 'ARTICLE_APPROVED', 'message' => 'ok', 'is_read' => false, 'created_at' => $this->ts()]);
        $this->insert('reporters_articlereview', ['article_id' => $art, 'reviewer_id' => $admin, 'action' => 'APPROVED', 'from_status' => 'SUBMITTED', 'to_status' => 'APPROVED', 'reason' => '', 'created_at' => $this->ts()]);
        $this->insert('reporters_reportercategoryassignment', ['reporter_id' => $rep, 'category_id' => $cat, 'assigned_by_id' => $admin, 'created_at' => $this->ts()]);
        $this->insert('advertisements_advertisement', ['name' => 'ad', 'placement' => 'HOME_TOP', 'creative_type' => 'IMAGE', 'image_url' => 'https://cdn.example.test/ad.png', 'bunny_storage_path' => '', 'target_url' => '', 'start_at' => '2026-01-01 00:00:00+00', 'end_at' => '2027-01-01 00:00:00+00', 'is_active' => true, 'priority' => 1, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
        $this->insert('analytics_articledailyview', ['article_id' => $art, 'date' => '2026-01-10', 'views' => 5, 'created_at' => $this->ts(), 'updated_at' => $this->ts()]);
        $this->insert('ai_aianalysisresult', ['article_id' => $art, 'requested_by_id' => $rep, 'provider' => 'OPENAI', 'model_name' => 'm', 'status' => 'COMPLETED', 'error_message' => '', 'grammar_issues' => '[]', 'seo_suggestions' => '["x"]', 'ai_content_rationale' => '', 'raw_response' => '{"secret":"raw"}', 'created_at' => $this->ts()]);
        $this->insert('ai_plagiarismcheckresult', ['article_id' => $art, 'requested_by_id' => $rep, 'provider' => 'COPYLEAKS', 'scan_id' => 'scan-fixture-1', 'status' => 'PENDING', 'error_message' => '', 'matches' => '[]', 'raw_response' => '{}', 'created_at' => $this->ts()]);

        return compact('admin', 'rep', 'reader', 'ind', 'cat', 'sub', 'tag', 'art', 'img', 'plan', 'sb', 'pay');
    }
}
