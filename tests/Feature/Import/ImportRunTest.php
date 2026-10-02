<?php

namespace Tests\Feature\Import;

use App\Models\User;
use App\Services\Import\ImportContext;
use App\Services\Import\Support\TargetSchema;
use App\Support\DjangoPbkdf2Hasher;
use Illuminate\Support\Facades\DB;

class ImportRunTest extends ImportTestCase
{
    public function test_clean_import_preserves_ids_and_passes_relationship_validation(): void
    {
        $ids = $this->legacy->baseline();

        [$exit, $r, $out] = $this->runImport();

        $this->assertSame(0, $exit, $out);
        $this->assertSame('completed', $r['run']['status']);
        $this->assertSame(0, $r['findings_summary']['blocking']);
        foreach (['users' => 3, 'industries' => 1, 'categories' => 1, 'subcategories' => 1, 'tags' => 1, 'articles' => 1, 'article_tag' => 1, 'article_images' => 1,
            'article_reviews' => 1, 'reporter_category_assignments' => 1, 'subscription_plans' => 1, 'subscriptions' => 1, 'payments' => 1, 'notifications' => 1,
            'advertisements' => 1, 'article_daily_views' => 1, 'ai_analysis_results' => 1, 'plagiarism_check_results' => 1] as $table => $n) {
            $this->assertSame($n, $this->rows($table), $table);
        }
        // primary keys preserved so relationships stay valid
        $a = DB::table('articles')->find($ids['art']);
        $this->assertSame($ids['rep'], (int) $a->author_id);
        $this->assertSame($ids['sub'], (int) $a->subcategory_id);
        $this->assertSame($ids['cat'], (int) $a->category_id);
        $this->assertSame($ids['sub'], (int) DB::table('subcategories')->find($ids['sub'])->id);
        $this->assertSame($ids['reader'], (int) DB::table('payments')->find($ids['pay'])->user_id);
        $this->assertSame('pay_FIXTURE_1', DB::table('payments')->find($ids['pay'])->razorpay_payment_id);
        $this->assertStringStartsWith('order_FIXTURE_', DB::table('payments')->find($ids['pay'])->razorpay_order_id);
        // image metadata folded into the jsonb column; exactly one featured
        $img = DB::table('article_images')->find($ids['img']);
        $this->assertTrue((bool) $img->is_featured);
        $this->assertEquals(['original_filename' => 'a.jpg', 'content_type' => 'image/jpeg', 'file_size_bytes' => 100, 'width' => 10, 'height' => 5, 'checksum' => str_repeat('a', 64)], json_decode($img->metadata, true));
        $this->assertTrue(DB::table('article_search_index')->where('article_id', $ids['art'])->exists(), 'search index rebuilt');
        $failed = array_filter($r['relationship_validation'], fn ($v) => $v['status'] === 'fail');
        $this->assertSame([], array_values($failed));
        $this->assertContains('fk:integrity', array_column($r['relationship_validation'], 'check'));
    }

    public function test_pbkdf2_hash_is_imported_unchanged_and_the_user_can_log_in(): void
    {
        $hash = DjangoPbkdf2Hasher::makeDjango('Legacy-Pass-1', 'saltsalt', 5000);
        $u = $this->legacy->user(['email' => 'Login.User@Example.test', 'password' => $hash]);
        $bad = $this->legacy->user(['email' => 'md5@example.test', 'password' => 'md5$salt$0123456789abcdef0123456789abcdef']);
        $unusable = $this->legacy->user(['email' => 'unusable@example.test', 'password' => '!'.str_repeat('x', 40)]);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);
        $this->assertSame(0, $exit);

        $this->assertSame($hash, DB::table('users')->find($u)->password, 'PBKDF2 hash copied byte for byte');
        $this->assertNotSame('!'.str_repeat('x', 40), DB::table('users')->find($unusable)->password);
        $this->assertCount(2, $this->findings($r, 'USR-PASSWORD-UNSUPPORTED'));

        $res = $this->postJson('/api/auth/login/', ['email' => 'login.user@example.test', 'password' => 'Legacy-Pass-1']);
        $res->assertOk()->assertJsonStructure(['access', 'refresh', 'user']);
        $this->assertStringStartsWith('$2y$', DB::table('users')->find($u)->password, 'upgraded to bcrypt on first login');

        $this->postJson('/api/auth/login/', ['email' => 'md5@example.test', 'password' => 'anything'])->assertStatus(401);
        $this->postJson('/api/auth/login/', ['email' => 'unusable@example.test', 'password' => 'anything'])->assertStatus(401);
        $this->assertSame($bad !== $unusable, true);
    }

    public function test_roles_superuser_and_subscriber_mapping(): void
    {
        $su = $this->legacy->user(['is_superuser' => true, 'role' => 'USER']);
        $admin = $this->legacy->user(['role' => 'ADMIN']);
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $sub = $this->legacy->user(['role' => 'SUBSCRIBER']);
        $weird = $this->legacy->user(['role' => 'MODERATOR']);
        $staff = $this->legacy->user(['is_staff' => true, 'role' => 'USER']);

        [$exit, $r] = $this->runImport(['--resolve-defaults' => true]);

        $this->assertSame(0, $exit);
        $roles = DB::table('users')->pluck('role', 'id')->all();
        $this->assertSame('ADMIN', $roles[$su]);
        $this->assertSame('ADMIN', $roles[$admin]);
        $this->assertSame('REPORTER', $roles[$rep]);
        $this->assertSame('SUBSCRIBER', $roles[$sub]);
        $this->assertSame('USER', $roles[$weird]);
        $this->assertSame('USER', $roles[$staff]);
        $this->assertSame($su, $this->findings($r, 'USR-SUPERUSER-ROLE')[0]['source_id']);
        $this->assertSame($weird, $this->findings($r, 'USR-ROLE-INVALID')[0]['source_id']);
        $this->assertSame(1, $r['stats']['users_is_staff_non_admin']);
        $this->assertSame('pass', collect($r['relationship_validation'])->firstWhere('check', 'Django superusers imported as ADMIN')['status']);
    }

    public function test_dry_run_writes_nothing_but_reports_what_would_happen(): void
    {
        $this->legacy->baseline();
        $before = TargetSchema::autoIncrement('users');

        [$exit, $r] = $this->runImport(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame('dry-run', $r['run']['mode']);
        $this->assertSame('dry-run-ok', $r['run']['status']);
        $this->assertSame(3, $r['entities']['users']['imported']);
        foreach (ImportContext::TARGET_TABLES as $table) {
            $this->assertSame(0, $this->rows($table), "dry-run wrote to {$table}");
        }
        $after = TargetSchema::autoIncrement('users');
        $this->assertSame($before, $after, 'AUTO_INCREMENT counters untouched');
        $this->assertSame([], $r['sequence_resets']);
    }

    public function test_dry_run_still_stops_on_blocking_findings(): void
    {
        $this->legacy->user(['email' => 'A@example.test']);
        $this->legacy->user(['email' => 'a@example.test']);

        [$exit, $r] = $this->runImport(['--dry-run' => true]);

        $this->assertSame(2, $exit);
        $this->assertSame('blocked', $r['run']['status']);
        $this->assertSame(0, $this->rows('users'));
    }

    public function test_rehearse_runs_everything_then_rolls_back(): void
    {
        $this->legacy->baseline();

        [$exit, $r] = $this->runImport(['--rehearse' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('rehearsal-ok', $r['run']['status']);
        $this->assertSame(3, $r['entities']['users']['inserted']);
        $this->assertNotEmpty($r['relationship_validation']);
        $this->assertSame(0, $this->rows('users'));
        $this->assertSame(0, $this->rows('articles'));
    }

    public function test_non_empty_target_is_refused_and_rerun_is_idempotent_with_allow_non_empty(): void
    {
        $this->legacy->baseline();
        [$exit] = $this->runImport();
        $this->assertSame(0, $exit);

        [$exit, $r] = $this->runImport();
        $this->assertSame(3, $exit);
        $this->assertSame('refused', $r['run']['status']);
        $this->assertStringContainsString('non-empty', $r['errors'][0]['message']);

        // a change in the legacy data is picked up; nothing is duplicated
        DB::table('users')->where('role', 'REPORTER')->update(['first_name' => 'Edited-in-target']);
        $this->legacy->exec("UPDATE accounts_user SET first_name = 'Changed-in-legacy' WHERE role = 'REPORTER'");
        $tags = $this->rows('tags');

        [$exit, $r] = $this->runImport(['--allow-non-empty' => true]);
        $this->assertSame(0, $exit, json_encode($r['errors']));
        $this->assertSame(3, $r['entities']['users']['updated']);
        $this->assertSame(0, $r['entities']['users']['inserted']);
        $this->assertSame(3, $this->rows('users'));
        $this->assertSame($tags, $this->rows('tags'));
        $this->assertSame(1, $this->rows('articles'));
        $this->assertSame('Changed-in-legacy', DB::table('users')->where('role', 'REPORTER')->value('first_name'));

        // and a second idempotent pass is a no-op in row counts
        [$exit, $r] = $this->runImport(['--allow-non-empty' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, $this->rows('article_images'));
    }

    public function test_sequences_are_reset_past_the_imported_ids(): void
    {
        $this->legacy->user(['id' => 500]);
        $this->legacy->user(['id' => 777, 'is_superuser' => true, 'role' => 'ADMIN']);
        $rep = $this->legacy->user(['id' => 900, 'role' => 'REPORTER']);
        $this->legacy->article(['id' => 4242, 'author_id' => $rep]);

        [$exit, $r] = $this->runImport();
        $this->assertSame(0, $exit);

        $new = User::factory()->create();
        $this->assertSame(901, (int) $new->id);
        $this->assertGreaterThan(4242, DB::table('articles')->insertGetId([
            'title' => 't', 'slug' => 'after-import', 'excerpt' => '', 'content' => 'c', 'faqs' => '[]', 'rejection_reason' => '', 'author_id' => 900, 'status' => 'DRAFT', 'access_level' => 'PUBLIC', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $seq = collect($r['sequence_resets'])->firstWhere('table', 'users');
        $this->assertSame(901, $seq['set_to']);
        $this->assertSame('AUTO_INCREMENT', $seq['sequence']);
        $this->assertSame(901, $seq['actual'], 'counter read back from information_schema');
        $this->assertSame(4243, collect($r['sequence_resets'])->firstWhere('table', 'articles')['actual']);
        $this->assertSame('pass', collect($r['relationship_validation'])->firstWhere('check', 'sequences ahead of MAX(id)')['status']);
    }

    public function test_only_option_limits_entities_and_rejects_unknown_names(): void
    {
        $this->legacy->baseline();

        [$exit, $r] = $this->runImport(['--only' => 'users,industries']);
        $this->assertSame(0, $exit);
        $this->assertSame(3, $this->rows('users'));
        $this->assertSame(1, $this->rows('industries'));
        $this->assertSame(0, $this->rows('articles'));
        $this->assertSame(['users', 'industries'], array_keys($r['entities']));

        [$exit, $r] = $this->runImport(['--only' => 'nonsense']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown entities', $r['errors'][0]['message']);
    }

    public function test_a_failing_entity_is_rolled_back_and_stops_the_run_without_leaking_data(): void
    {
        $rep = $this->legacy->user(['role' => 'REPORTER']);
        $this->legacy->article(['author_id' => $rep, 'title' => 'first']);
        $this->legacy->article(['author_id' => $rep, 'title' => 'BOOM-secret-title']);
        DB::statement("ALTER TABLE articles ADD CONSTRAINT no_boom CHECK (title <> 'BOOM-secret-title')");
        try {
            [$exit, $r, $out] = $this->runImport();
        } finally {
            DB::statement('ALTER TABLE articles DROP CONSTRAINT no_boom');
        }

        $this->assertSame(20, $exit);
        $this->assertSame('failed', $r['run']['status']);
        $this->assertSame(0, $this->rows('articles'), 'the failing entity is rolled back completely');
        $this->assertSame(1, $this->rows('users'), 'earlier entities stay committed');
        $this->assertSame('articles', $r['errors'][0]['entity']);
        $this->assertStringNotContainsString('BOOM-secret-title', json_encode($r).$out, 'no row data in errors');
        $this->assertSame(0, $this->rows('article_tag'));
    }

    public function test_payments_keep_razorpay_ids_and_secrets_stay_out_of_the_report(): void
    {
        $ids = $this->legacy->baseline();

        [$exit, $r, $out] = $this->runImport();

        $this->assertSame(0, $exit);
        $p = DB::table('payments')->find($ids['pay']);
        $this->assertSame('sig-fixture-secret-value', $p->razorpay_signature, 'stored for the payment record');
        $this->assertStringNotContainsString('sig-fixture-secret-value', json_encode($r).$out);
        $this->assertStringNotContainsString('pbkdf2_sha256$', json_encode($r).$out);
    }
}
