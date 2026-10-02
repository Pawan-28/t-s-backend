<?php

namespace Tests\Feature\Import;

use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\ArticleDailyView;
use App\Models\PlagiarismCheckResult;
use App\Models\User;
use App\Services\Import\Entities\UserImporter;
use App\Services\Import\Support\Collation;
use App\Services\Import\Support\ImportAbort;
use App\Services\Import\Support\TargetSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * MySQL/MariaDB-target specifics of the importer: DATETIME wall-clock semantics, collation-equal duplicates,
 * column limits, AUTO_INCREMENT, JSON columns, the generated single-PENDING column, statement sizing, search index.
 */
class ImportMysqlTargetTest extends ImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These assertions are about IST wall clocks: pin the zone regardless of the environment's APP_TIMEZONE.
        config(['app.timezone' => 'Asia/Kolkata']);
        date_default_timezone_set('Asia/Kolkata');
    }

    // ------------------------------------------------------------------ datetime

    public function test_instants_are_converted_to_app_timezone_wall_clock_and_round_trip_to_the_same_instant(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $rep = $this->legacy->user(['role' => 'REPORTER', 'last_login' => '2026-03-01 18:30:00+00', 'created_at' => '2026-01-15 10:00:00+00']);
        // stored in UTC (10:00Z = 15:30 IST); an offset-suffixed literal from another zone; sub-second precision
        $a = $this->legacy->article(['author_id' => $rep, 'slug' => 'tz-article', 'created_at' => '2026-01-15 10:00:00+00', 'published_at' => '2026-06-30 23:45:10-05', 'updated_at' => '2026-01-15 10:00:00.987654+00', 'scheduled_publish_at' => null]);
        $this->legacy->insert('analytics_articledailyview', ['article_id' => $a, 'date' => '2026-01-10', 'views' => 5, 'created_at' => '2026-01-10 20:00:00+00', 'updated_at' => '2026-01-10 20:00:00+00']);

        [$exit, $r, $out] = $this->runImport();
        $this->assertSame(0, $exit, $out);

        // raw column values are app-timezone wall clock WITHOUT offset
        $row = DB::table('articles')->find($a);
        $this->assertSame('2026-01-15 15:30:00', $row->created_at);
        $this->assertSame('2026-07-01 10:15:10', $row->published_at, '23:45:10-05 == 04:45:10Z == 10:15:10 IST');
        $this->assertSame('2026-01-15 15:30:00', $row->updated_at, 'microseconds are cut, not rounded up');
        $this->assertNull($row->scheduled_publish_at);
        $this->assertSame('2026-03-02 00:00:00', DB::table('users')->find($rep)->last_login, '18:30Z is midnight IST of the next day');
        foreach (['articles' => ['created_at', 'published_at', 'updated_at'], 'users' => ['created_at', 'updated_at']] as $table => $cols) {
            foreach ($cols as $c) {
                $this->assertDoesNotMatchRegularExpression('/[+-]\d\d(:?\d\d)?$/', (string) DB::table($table)->whereNotNull($c)->value($c), "{$table}.{$c} carries an offset");
            }
        }

        // the Eloquent model reads the same INSTANT back
        $article = Article::find($a);
        $this->assertSame('2026-01-15 10:00:00', $article->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-01 04:45:10', $article->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 18:30:00', User::find($rep)->last_login->utc()->format('Y-m-d H:i:s'));

        // ... and so does the public API
        $json = $this->getJson('/api/articles/tz-article/')->assertOk()->json();
        $this->assertSame('2026-07-01 04:45:10', CarbonImmutable::parse($json['published_at'])->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-15 10:00:00', CarbonImmutable::parse($json['created_at'])->utc()->format('Y-m-d H:i:s'));

        // DATE columns stay dates (no timezone shift, no time part)
        $this->assertSame('2026-01-10', DB::table('article_daily_views')->value('date'));
        $this->assertSame('2026-01-10', ArticleDailyView::first()->date->format('Y-m-d'));
        $this->assertSame('2026-01-11 01:30:00', DB::table('article_daily_views')->value('created_at'), '20:00Z on the 10th is 01:30 IST on the 11th');
    }

    public function test_the_conversion_follows_the_configured_app_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $a = $this->legacy->article(['author_id' => $rep, 'created_at' => '2026-01-15 10:00:00+00', 'published_at' => '2026-07-15 10:00:00+00']);

        [$exit] = $this->runImport();

        $this->assertSame(0, $exit);
        $this->assertSame('2026-01-15 05:00:00', DB::table('articles')->find($a)->created_at, 'EST, UTC-5');
        $this->assertSame('2026-07-15 06:00:00', DB::table('articles')->find($a)->published_at, 'EDT, UTC-4');
    }

    public function test_a_dry_run_reports_datetime_conversion_problems_before_anything_is_written(): void
    {
        $this->assertSame('2026-01-15 15:30:00', TargetSchema::wallClock('2026-01-15 10:00:00+00'));
        $this->assertSame('2026-01-15 15:30:00', TargetSchema::wallClock('2026-01-15 10:00:00'), 'naive values are read as UTC (the legacy session zone)');
        $this->assertSame('2026-01-15 15:30:00', TargetSchema::wallClock('2026-01-15T15:30:00+05:30'));
        $this->expectException(ImportAbort::class);
        TargetSchema::wallClock('0001-01-01 00:00:00+00');
    }

    // ------------------------------------------------------------------ collation

    public function test_collation_equal_emails_are_detected_and_aliased_so_no_duplicate_key_error_can_occur(): void
    {
        $a = $this->legacy->user(['email' => 'José@Example.test']);
        $b = $this->legacy->user(['email' => 'jose@example.test']);
        $c = $this->legacy->user(['email' => '  JOSE@example.test  ']);
        $d = $this->legacy->user(['email' => 'ÉLAN@example.test']);
        $e = $this->legacy->user(['email' => 'elan@example.test']);
        $f = $this->legacy->user(['email' => 'unique@example.test']);
        // the whole point: PostgreSQL / PHP see a..c as different strings, the MySQL unique index does not
        $this->assertNotSame('josé@example.test', 'jose@example.test');

        [$exit, $r] = $this->runImport();
        $this->assertSame(2, $exit);
        $this->assertSame(0, $this->rows('users'));
        $dups = $this->findings($r, 'USR-EMAIL-CASE-DUP');
        $this->assertEqualsCanonicalizing([$b, $c, $e], array_column($dups, 'source_id'));
        $this->assertContainsOnly('string', array_column($dups, 'old'));
        $this->assertStringNotContainsString('jose', json_encode($r), 'emails are masked');

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(6, $this->rows('users'));
        $this->assertSame('josé@example.test', DB::table('users')->find($a)->email, 'the primary keeps its address');
        $this->assertTrue((bool) DB::table('users')->find($a)->is_active);
        foreach ([$b, $c, $e] as $id) {
            $this->assertFalse((bool) DB::table('users')->find($id)->is_active);
            $this->assertMatchesRegularExpression('/\+dup'.$id.'@example\.test$/', DB::table('users')->find($id)->email);
        }
        $this->assertSame(0, DB::table('users')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->get()->count());
        $this->assertSame([], array_values(array_filter($r['relationship_validation'], fn ($v) => $v['status'] === 'fail')));
    }

    public function test_an_address_held_in_the_target_under_the_collation_is_a_conflict_not_a_crash(): void
    {
        $legacy = $this->legacy->user(['email' => 'José@example.test']);
        [$exit] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit);
        // a different, newer target-only user takes the accent-free spelling; the legacy row is re-imported
        User::factory()->create(['email' => 'jose2@example.test']);
        DB::table('users')->where('id', $legacy)->delete();
        $other = User::factory()->create(['email' => 'jose@example.test']);
        $this->assertNotSame($legacy, (int) $other->id);
        $this->legacy->exec("UPDATE accounts_user SET email = 'José@example.test' WHERE id = {$legacy}");

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true, '--allow-non-empty' => true, '--only' => 'users']);

        $this->assertSame(0, $exit, $out);
        $this->assertNotEmpty($this->findings($r, 'USR-EMAIL-TARGET-CONFLICT'));
        $this->assertSame('jose@example.test', DB::table('users')->find($other->id)->email, 'the other target user is untouched');
        $this->assertFalse((bool) DB::table('users')->find($legacy)->is_active);
    }

    public function test_slugs_names_and_gateway_ids_that_only_differ_by_case_accent_or_trailing_space_are_resolved(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $cafeDraft = $this->legacy->article(['author_id' => $rep, 'slug' => 'café', 'status' => 'DRAFT', 'published_at' => null]);
        $cafe = $this->legacy->article(['author_id' => $rep, 'slug' => 'cafe', 'status' => 'PUBLISHED']);
        $trail = $this->legacy->article(['author_id' => $rep, 'slug' => 'trail', 'status' => 'PUBLISHED']);
        $trailSp = $this->legacy->article(['author_id' => $rep, 'slug' => 'trail ', 'status' => 'DRAFT', 'published_at' => null]);
        $t1 = $this->legacy->tag(['name' => 'News', 'slug' => 'n1']);
        $t2 = $this->legacy->tag(['name' => 'news', 'slug' => 'n2']);
        $t3 = $this->legacy->tag(['name' => 'Néws', 'slug' => 'n3']);
        $c1 = $this->legacy->category(['name' => 'Sports', 'slug' => 's1']);
        $c2 = $this->legacy->category(['name' => 'sports ', 'slug' => 's2']);
        $i1 = $this->legacy->industry(['name' => 'Media', 'slug' => 'm1']);
        $i2 = $this->legacy->industry(['name' => 'MÉDIA', 'slug' => 'm2']);
        $s1 = $this->legacy->subcategory(['category_id' => $c1, 'name' => 'A', 'slug' => 'under']);
        $s2 = $this->legacy->subcategory(['category_id' => $c1, 'name' => 'B', 'slug' => 'Ünder']);
        $plan = $this->legacy->plan();
        $u = $this->legacy->user();
        $sub = $this->legacy->subscription(['user_id' => $u, 'plan_id' => $plan]);
        $p1 = $this->legacy->payment(['subscription_id' => $sub, 'user_id' => $u, 'razorpay_order_id' => 'order_AbC']);
        $p2 = $this->legacy->payment(['subscription_id' => $sub, 'user_id' => $u, 'razorpay_order_id' => 'ORDER_abc']);
        $pl1 = $this->legacy->insert('ai_plagiarismcheckresult', ['article_id' => $cafe, 'provider' => 'COPYLEAKS', 'scan_id' => 'Scan-1', 'status' => 'PENDING', 'error_message' => '', 'matches' => '[]', 'raw_response' => '{}', 'created_at' => '2026-01-01 00:00:00+00']);
        $pl2 = $this->legacy->insert('ai_plagiarismcheckresult', ['article_id' => $cafe, 'provider' => 'COPYLEAKS', 'scan_id' => 'scan-1', 'status' => 'PENDING', 'error_message' => '', 'matches' => '[]', 'raw_response' => '{}', 'created_at' => '2026-01-01 00:00:00+00']);

        [$exit, $r] = $this->runImport();
        $this->assertSame(2, $exit);
        $this->assertSame(0, $this->rows('tags'));
        // Opaque gateway ids may be BINARY (case-sensitive) in the target schema; the importer asks the column for its collation.
        $ciIds = ! str_ends_with(Collation::forColumn('payments', 'razorpay_order_id')->collation(), '_bin');
        $ciScan = ! str_ends_with(Collation::forColumn('plagiarism_check_results', 'scan_id')->collation(), '_bin');
        $expected = ['TAG-NAME-CASE-DUP' => [$t2, $t3], 'CAT-NAME-DUP' => [$c2], 'IND-NAME-DUP' => [$i2]];
        $ciIds && $expected['PAY-ORDERID-DUP'] = [$p2];
        $ciScan && $expected['PLG-SCANID-DUP'] = [$pl2];
        $this->assertSame([], $ciIds ? [] : $this->findings($r, 'PAY-ORDERID-DUP'), 'case-sensitive column: different case is not a duplicate');
        $this->assertSame([], $ciScan ? [] : $this->findings($r, 'PLG-SCANID-DUP'));
        foreach ($expected as $rule => $ids) {
            $f = $this->findings($r, $rule);
            $this->assertEqualsCanonicalizing($ids, array_column($f, 'source_id'), $rule);
            $this->assertSame('blocking', $f[0]['severity']);
        }
        $this->assertSame([$cafeDraft, $trailSp], array_column($this->findings($r, 'SLUG-DUP', 'articles'), 'source_id'));
        $this->assertSame([$s2], array_column($this->findings($r, 'SLUG-DUP', 'subcategories'), 'source_id'));

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit, $out);

        $this->assertSame('cafe', DB::table('articles')->find($cafe)->slug);
        $this->assertSame('café-2', DB::table('articles')->find($cafeDraft)->slug);
        $this->assertSame('trail', DB::table('articles')->find($trail)->slug);
        $this->assertSame('trail-2', DB::table('articles')->find($trailSp)->slug);
        $this->assertSame('News', DB::table('tags')->find($t1)->name);
        $this->assertSame('news (2)', DB::table('tags')->find($t2)->name);
        $this->assertSame('Néws (3)', DB::table('tags')->find($t3)->name);
        $this->assertSame('Sports', DB::table('categories')->find($c1)->name);
        $this->assertSame('sports  (2)', DB::table('categories')->find($c2)->name);
        $this->assertSame('MÉDIA (2)', DB::table('industries')->find($i2)->name);
        $this->assertSame('Ünder-2', DB::table('subcategories')->find($s2)->slug);
        $this->assertSame('order_AbC', DB::table('payments')->find($p1)->razorpay_order_id);
        $this->assertSame($ciIds ? "ORDER_abc~dup{$p2}" : 'ORDER_abc', DB::table('payments')->find($p2)->razorpay_order_id);
        $this->assertSame($ciScan ? "scan-1~dup{$pl2}" : 'scan-1', DB::table('plagiarism_check_results')->find($pl2)->scan_id);
        // nothing is lost: same row counts as the source
        $this->assertSame(4, $this->rows('articles'));
        $this->assertSame(3, $this->rows('tags'));
        $this->assertSame(2, $this->rows('payments'));
        foreach ([['articles', 'slug'], ['tags', 'name'], ['categories', 'name'], ['industries', 'name'], ['payments', 'razorpay_order_id']] as [$t, $c]) {
            $this->assertSame(0, DB::table($t)->select($c)->groupBy($c)->havingRaw('COUNT(*) > 1')->get()->count(), "{$t}.{$c}");
        }
    }

    public function test_gateway_ids_are_deduplicated_when_the_column_is_case_insensitive_and_left_alone_when_it_is_binary(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $art = $this->legacy->article(['author_id' => $rep]);
        $mk = fn (string $scan) => $this->legacy->insert('ai_plagiarismcheckresult', ['article_id' => $art, 'provider' => 'COPYLEAKS', 'scan_id' => $scan, 'status' => 'PENDING', 'error_message' => '', 'matches' => '[]', 'raw_response' => '{}', 'created_at' => '2026-01-01 00:00:00+00']);
        $a = $mk('AbC-1');
        $b = $mk('abc-1');
        $original = TargetSchema::columns('plagiarism_check_results')['scan_id']['collation'];
        try {
            foreach (['utf8mb4_unicode_ci' => true, 'utf8mb4_bin' => false] as $collation => $collides) {
                DB::table('plagiarism_check_results')->delete();
                DB::statement("ALTER TABLE plagiarism_check_results MODIFY scan_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation} NOT NULL");
                [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true, '--allow-non-empty' => true, '--only' => 'users,categories,subcategories,industries,tags,subscription_plans,articles,plagiarism_checks']);
                $this->assertSame(0, $exit, $out);
                $this->assertCount($collides ? 1 : 0, $this->findings($r, 'PLG-SCANID-DUP'), $collation);
                $this->assertSame($collides ? "abc-1~dup{$b}" : 'abc-1', DB::table('plagiarism_check_results')->find($b)->scan_id);
                $this->assertSame(2, $this->rows('plagiarism_check_results'));
            }
        } finally {
            DB::statement("ALTER TABLE plagiarism_check_results MODIFY scan_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$original} NOT NULL");
        }
    }

    public function test_collation_keys_come_from_the_server_and_follow_the_unique_index_rules(): void
    {
        $c = Collation::forColumn('users', 'email');
        $c->prime(['José@x.test', 'jose@x.test', 'JOSE@X.TEST   ', 'jose@x.tesT2']);
        $this->assertSame($c->key('José@x.test'), $c->key('jose@x.test'));
        $this->assertSame($c->key('jose@x.test'), $c->key('JOSE@X.TEST   '), 'PAD SPACE: trailing spaces are ignored');
        $this->assertNotSame($c->key('jose@x.test'), $c->key('jose@x.tesT2'));
        $this->assertStringStartsWith('w:', $c->key('a'), 'weights computed by the server (WEIGHT_STRING)');
        $this->assertSame($c->key('Straße'), $c->key('STRASSE'));
        // the key is exactly what the unique index applies
        DB::table('users')->insert(['email' => 'José@x.test', 'password' => 'x', 'first_name' => '', 'last_name' => '']);
        $this->expectException(QueryException::class);
        DB::table('users')->insert(['email' => 'jose@x.test', 'password' => 'x', 'first_name' => '', 'last_name' => '']);
    }

    // ------------------------------------------------------------------ column limits

    public function test_over_long_text_is_reported_then_truncated_within_the_byte_limit_without_breaking_utf8(): void
    {
        // rejection_reason is a TEXT column (65535 BYTES) in every schema variant (excerpt may have been widened to MEDIUMTEXT)
        $this->assertSame(65535, TargetSchema::stringLimit('articles', 'rejection_reason')[1] ?? null);
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $long = str_repeat('é', 40000); // 80000 bytes > TEXT
        $a = $this->legacy->article(['author_id' => $rep, 'rejection_reason' => $long]);
        $fine = $this->legacy->article(['author_id' => $rep, 'rejection_reason' => str_repeat('é', 30000)]); // 60000 bytes: fits

        [$exit, $r] = $this->runImport();
        $this->assertSame(2, $exit, 'value would be altered: blocking');
        $this->assertSame(0, $this->rows('articles'));
        $f = array_values(array_filter($this->findings($r, 'LEN-TRUNCATED', 'articles'), fn ($x) => str_contains($x['reason'], 'rejection_reason')));
        $this->assertSame([$a], array_column($f, 'source_id'));
        $this->assertSame('blocking', $f[0]['severity']);
        $this->assertSame(80000, $f[0]['old']);
        $this->assertSame(65535, $f[0]['new']);

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit, $out);
        $stored = (string) DB::table('articles')->find($a)->rejection_reason;
        $this->assertLessThanOrEqual(65535, strlen($stored));
        $this->assertGreaterThan(65500, strlen($stored));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
        $this->assertSame(str_repeat('é', 30000), DB::table('articles')->find($fine)->rejection_reason, 'values that fit are untouched');
        $this->assertSame(1, $r['stats']['values_truncated_to_column_limit']);
        $this->assertEmpty($this->findings($r, 'LEN-TRUNCATED-UNANNOUNCED'));
        // the source keeps the full original
        $this->assertSame(80000, (int) $this->legacy->query("SELECT octet_length(rejection_reason) AS n FROM articles_article WHERE id = {$a}")[0]['n']);
    }

    public function test_over_long_varchar_values_are_reported_and_truncated_by_characters(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $a = $this->legacy->article(['author_id' => $rep, 'location_name' => 'Mumbai, Maharashtra, India']);
        DB::statement('ALTER TABLE articles MODIFY location_name VARCHAR(10) NOT NULL DEFAULT \'\'');
        try {
            [$exit, $r] = $this->runImport();
            $this->assertSame(2, $exit);
            $f = $this->findings($r, 'LEN-TRUNCATED', 'articles');
            $this->assertCount(1, $f);
            $this->assertSame(26, $f[0]['old']);
            $this->assertSame(10, $f[0]['new']);
            $this->assertStringContainsString('location_name', $f[0]['reason']);

            [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
            $this->assertSame(0, $exit, $out);
            $this->assertSame('Mumbai, Ma', DB::table('articles')->find($a)->location_name);
        } finally {
            DB::statement('ALTER TABLE articles MODIFY location_name VARCHAR(200) NOT NULL DEFAULT \'\'');
        }
    }

    public function test_an_alias_email_never_overflows_the_email_column(): void
    {
        $local = str_repeat('a', 240);
        $a = $this->legacy->user(['email' => "{$local}@ex.test", 'is_active' => true]);
        $b = $this->legacy->user(['email' => strtoupper("{$local}@ex.test"), 'is_active' => false]);

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit, $out);
        $email = DB::table('users')->find($b)->email;
        $this->assertLessThanOrEqual(254, mb_strlen($email));
        $this->assertStringEndsWith("+dup{$b}@ex.test", $email);
    }

    // ------------------------------------------------------------------ AUTO_INCREMENT

    public function test_auto_increment_counters_end_exactly_past_max_id_even_after_a_rehearsal_advanced_them(): void
    {
        $rep = $this->legacy->user(['id' => 900, 'role' => 'REPORTER']);
        $this->legacy->user(['id' => 20]);
        $this->legacy->article(['id' => 4242, 'author_id' => $rep]);

        [$exit] = $this->runImport(['--rehearse' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(0, $this->rows('users'));
        $this->assertGreaterThan(900, TargetSchema::autoIncrement('users'), 'InnoDB does not roll the counter back: the rehearsal advanced it');

        [$exit, $r] = $this->runImport();
        $this->assertSame(0, $exit);
        $this->assertSame(901, TargetSchema::autoIncrement('users'));
        $this->assertSame(4243, TargetSchema::autoIncrement('articles'));
        $this->assertSame(1, TargetSchema::autoIncrement('tags'), 'empty tables restart at 1');
        $row = collect($r['sequence_resets'])->firstWhere('table', 'users');
        $this->assertSame(['AUTO_INCREMENT', 901, 901], [$row['sequence'], $row['set_to'], $row['actual']]);
        $this->assertNotNull($row['before']);
        $this->assertCount(19, $r['sequence_resets']);
        $this->assertSame(901, (int) DB::table('users')->insertGetId(['email' => 'next@example.test', 'password' => 'x', 'first_name' => '', 'last_name' => '']));
    }

    // ------------------------------------------------------------------ JSON

    public function test_json_columns_always_hold_valid_json_and_faqs_default_to_an_empty_list(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $faq = [['question' => 'क्या?', 'answer' => 'Yes "quoted" / slash / 😀 emoji']];
        $ok = $this->legacy->article(['author_id' => $rep, 'faqs' => json_encode($faq, JSON_UNESCAPED_UNICODE)]);
        $obj = $this->legacy->article(['author_id' => $rep, 'faqs' => '{"not":"a list"}']);
        $null = $this->legacy->article(['author_id' => $rep, 'faqs' => 'null']);
        $scalar = $this->legacy->article(['author_id' => $rep, 'faqs' => '"text"']);
        $img = $this->legacy->image(['article_id' => $ok, 'uploaded_by_id' => $rep, 'is_featured' => true]);
        $this->legacy->insert('media_mediametadata', ['image_id' => $img, 'original_filename' => 'zażółć "x".jpg', 'content_type' => 'image/jpeg', 'file_size_bytes' => 2147483647, 'width' => 10, 'height' => 5, 'checksum' => str_repeat('b', 64), 'created_at' => '2026-01-01 00:00:00+00']);
        $bare = $this->legacy->image(['article_id' => $ok, 'uploaded_by_id' => $rep]);
        $ai = $this->legacy->insert('ai_aianalysisresult', ['article_id' => $ok, 'provider' => 'OPENAI', 'model_name' => 'm', 'status' => 'COMPLETED', 'error_message' => '', 'grammar_issues' => '[{"message":"tëst","offset":1.0}]', 'seo_suggestions' => '{"title":"x","tips":[1,2]}', 'ai_content_rationale' => '', 'raw_response' => '{}', 'created_at' => '2026-01-01 00:00:00+00']);
        $pl = $this->legacy->insert('ai_plagiarismcheckresult', ['article_id' => $ok, 'provider' => 'COPYLEAKS', 'scan_id' => 'scan-json', 'status' => 'COMPLETED', 'error_message' => '', 'matches' => '[{"url":"https://x.test/?a=1&b=2","score":0.5}]', 'raw_response' => '{}', 'created_at' => '2026-01-01 00:00:00+00']);

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit, $out);

        foreach ([['articles', 'faqs'], ['article_images', 'metadata'], ['ai_analysis_results', 'grammar_issues'], ['ai_analysis_results', 'seo_suggestions'], ['plagiarism_check_results', 'matches']] as [$t, $c]) {
            $this->assertSame(0, (int) DB::table($t)->whereNotNull($c)->whereRaw("NOT JSON_VALID(`{$c}`)")->count(), "{$t}.{$c} holds invalid JSON");
        }
        $this->assertEquals($faq, json_decode(DB::table('articles')->find($ok)->faqs, true));
        $this->assertEquals($faq, Article::find($ok)->faqs, 'the model casts it back to the same array');
        foreach ([$obj, $null, $scalar] as $id) {
            $this->assertSame('[]', DB::table('articles')->find($id)->faqs);
        }
        $this->assertCount(3, $this->findings($r, 'ART-FAQS-INVALID'));
        $meta = json_decode(DB::table('article_images')->find($img)->metadata, true);
        $this->assertSame('zażółć "x".jpg', $meta['original_filename']);
        $this->assertNull(DB::table('article_images')->find($bare)->metadata);
        $this->assertEquals([['message' => 'tëst', 'offset' => 1.0]], AiAnalysisResult::find($ai)->grammar_issues); // jsonb reorders keys
        $this->assertEquals(['title' => 'x', 'tips' => [1, 2]], AiAnalysisResult::find($ai)->seo_suggestions);
        $this->assertEquals([['url' => 'https://x.test/?a=1&b=2', 'score' => 0.5]], PlagiarismCheckResult::find($pl)->matches);
    }

    // ------------------------------------------------------------------ generated column

    public function test_the_generated_pending_column_is_never_written_and_the_single_pending_rule_holds(): void
    {
        $admin = $this->legacy->user(['role' => 'ADMIN', 'is_superuser' => true]);
        $art = $this->legacy->article(['author_id' => $admin, 'status' => 'SCHEDULED', 'published_at' => null]);
        $art2 = $this->legacy->article(['author_id' => $admin, 'status' => 'SCHEDULED', 'published_at' => null]);
        $old = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => $admin, 'created_at' => '2026-01-01 00:00:00+00']);
        $mid = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => $admin, 'created_at' => '2026-01-03 00:00:00+00']);
        $new = $this->legacy->schedule(['article_id' => $art, 'scheduled_by_id' => $admin, 'created_at' => '2026-01-05 00:00:00+00']);
        $other = $this->legacy->schedule(['article_id' => $art2, 'scheduled_by_id' => $admin]);
        $done = $this->legacy->schedule(['article_id' => $art2, 'scheduled_by_id' => $admin, 'status' => 'EXECUTED']);

        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            if (str_contains($q->sql, 'publishing_schedules') && preg_match('/^\s*insert/i', $q->sql)) {
                $statements[] = $q->sql;
            }
        });

        [$exit, $r, $out] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertStringNotContainsString('pending_article_id', $sql);
        }
        $status = DB::table('publishing_schedules')->pluck('status', 'id')->all();
        $this->assertSame('CANCELLED', $status[$old]);
        $this->assertSame('CANCELLED', $status[$mid]);
        $this->assertSame('PENDING', $status[$new]);
        $this->assertSame('PENDING', $status[$other]);
        $this->assertSame('EXECUTED', $status[$done]);
        $gen = DB::table('publishing_schedules')->pluck('pending_article_id', 'id')->all();
        $this->assertSame([$new => $art, $other => $art2], array_filter($gen, fn ($v) => $v !== null), 'the database derived it');
        $this->assertCount(2, $this->findings($r, 'SCH-MULTI-PENDING'));
        $this->assertSame('pass', collect($r['relationship_validation'])->firstWhere('check', 'single PENDING schedule per article')['status']);

        // idempotent re-run keeps satisfying the unique index
        [$exit] = $this->runImport(['--resolve-defaults' => true, '--allow-non-empty' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(2, DB::table('publishing_schedules')->where('status', 'PENDING')->count());
        // and the database itself still rejects a second PENDING row
        $this->expectException(QueryException::class);
        DB::table('publishing_schedules')->insert(['article_id' => $art, 'scheduled_for' => '2027-01-01 00:00:00', 'scheduled_by_id' => $admin, 'status' => 'PENDING']);
    }

    // ------------------------------------------------------------------ statement sizing

    public function test_statements_respect_the_placeholder_limit_and_the_byte_budget(): void
    {
        $imp = new UserImporter;
        $m = new \ReflectionMethod($imp, 'batches');
        $m->setAccessible(true);

        // 20000 rows x 14 columns = 280000 placeholders -> every statement below 60000 placeholders
        $rows = [];
        for ($i = 1; $i <= 20000; $i++) {
            $rows[] = array_fill_keys(['id', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm'], 1);
        }
        $n = 0;
        foreach ($m->invoke($imp, $rows, 'users') as $batch) {
            $n += count($batch);
            $this->assertLessThan(60000, count($batch) * 14);
        }
        $this->assertSame(20000, $n);

        // LONGTEXT-heavy rows: 1 MB each; a statement never exceeds the byte budget (a quarter of max_allowed_packet, max 4 MB)
        $budget = TargetSchema::writeBudgetBytes();
        $this->assertLessThanOrEqual(4 * 1024 * 1024, $budget);
        $heavy = [];
        for ($i = 1; $i <= 30; $i++) {
            $heavy[] = ['id' => $i, 'content' => str_repeat('x', 1024 * 1024)];
        }
        $batches = iterator_to_array($m->invoke($imp, $heavy, 'articles'), false);
        $this->assertSame(30, array_sum(array_map('count', $batches)));
        foreach ($batches as $b) {
            $this->assertLessThanOrEqual(max(1, intdiv($budget, 1024 * 1024)), count($b));
        }
        $this->assertGreaterThan(5, count($batches));

        // a single row that cannot fit in max_allowed_packet aborts with a clear message (no row data)
        $this->expectException(ImportAbort::class);
        iterator_to_array($m->invoke($imp, [['id' => 7, 'content' => str_repeat('x', TargetSchema::maxAllowedPacket() + 10)]], 'articles'));
    }

    public function test_a_multi_chunk_import_with_a_tiny_chunk_size_is_complete(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        for ($i = 0; $i < 25; $i++) {
            $this->legacy->user();
            $this->legacy->article(['author_id' => $rep, 'content' => '<p>'.str_repeat('body ', 200).'</p>']);
        }

        [$exit, $r, $out] = $this->runImport(['--chunk' => 4]);

        $this->assertSame(0, $exit, $out);
        $this->assertSame(26, $this->rows('users'));
        $this->assertSame(25, $this->rows('articles'));
        $this->assertSame(25, $this->rows('article_search_index'));
        $this->assertSame($r['entities']['articles']['source_count'], $r['entities']['articles']['imported']);
    }

    // ------------------------------------------------------------------ search index

    public function test_the_fulltext_search_index_is_rebuilt_after_the_import_and_can_be_skipped(): void
    {
        $ids = $this->legacy->baseline();
        $this->legacy->exec("UPDATE articles_article SET title = 'Zanzibar harbour festival', content = '<p>lanterns everywhere</p>' WHERE id = {$ids['art']}");

        [$exit, $r, $out] = $this->runImport(['--skip-search-index' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(0, $this->rows('article_search_index'));
        $this->assertArrayNotHasKey('search_index_rebuilt_articles', $r['stats']);

        [$exit, $r, $out] = $this->runImport(['--allow-non-empty' => true]);
        $this->assertSame(0, $exit, $out);
        $this->assertSame(1, $r['stats']['search_index_rebuilt_articles']);
        $this->assertSame(1, $this->rows('article_search_index'));
        $hit = fn (string $q) => (int) DB::selectOne('SELECT COUNT(*) AS n FROM article_search_index WHERE MATCH(title, excerpt, body, taxonomy) AGAINST (? IN BOOLEAN MODE)', [$q])->n;
        $this->assertSame(1, $hit('zanzibar'));
        $this->assertSame(1, $hit('lanterns'));
        $this->assertSame(0, $hit('nothinghere'));
        $this->assertSame(1, $hit(DB::table('tags')->value('name')), 'tag names are indexed as taxonomy');
    }

    public function test_a_rehearsal_exercises_the_search_indexer_inside_its_rolled_back_transaction(): void
    {
        $this->legacy->baseline();

        [$exit, $r] = $this->runImport(['--rehearse' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $r['stats']['search_index_rebuilt_articles']);
        $this->assertSame(0, $this->rows('article_search_index'));
        $this->assertSame([], $r['sequence_resets'], 'DDL never runs inside the rehearsal transaction');
    }

    // ------------------------------------------------------------------ misc

    public function test_driver_errors_never_leak_quoted_values(): void
    {
        $pdo = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'alice@example.com' for key 'users.users_email_unique'");
        $e = new QueryException('mysql', 'insert into users (email) values (?)', ['alice@example.com'], $pdo);
        $msg = ImportAbort::sanitize($e);
        $this->assertStringNotContainsString('alice', $msg);
        $this->assertStringContainsString('Duplicate entry', $msg);

        $e2 = new QueryException('mysql', 'insert', ['x'], new \PDOException("SQLSTATE[HY000]: General error: 1366 Incorrect string value: '\\xF0\\x9F' for column `db`.`t`.`c` at row 1"));
        $this->assertStringNotContainsString('F0', ImportAbort::sanitize($e2));
    }

    public function test_every_not_null_column_without_a_database_default_is_supplied_by_the_importers(): void
    {
        $need = [];
        // Behavioural proof instead of reflection: a full baseline import supplies all of them (else the importer aborts).
        $this->legacy->baseline();
        [$exit, $r, $out] = $this->runImport();
        $this->assertSame(0, $exit, $out);
        $this->assertStringNotContainsString('does not supply NOT NULL', $out);
        foreach (['users', 'articles', 'article_reviews', 'ai_analysis_results', 'plagiarism_check_results', 'industries', 'categories', 'subcategories', 'subscription_plans'] as $table) {
            $need[$table] = TargetSchema::requiredColumns($table);
        }
        $this->assertContains('excerpt', $need['articles']);
        $this->assertContains('faqs', $need['articles']);
        $this->assertContains('grammar_issues', $need['ai_analysis_results']);
        $this->assertContains('matches', $need['plagiarism_check_results']);
        $this->assertNotContains('pending_article_id', TargetSchema::requiredColumns('publishing_schedules'));
    }
}
