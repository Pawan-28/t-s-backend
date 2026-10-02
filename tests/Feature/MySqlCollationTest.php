<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Payment;
use App\Models\PlagiarismCheckResult;
use App\Models\Tag;
use App\Models\User;
use App\Services\Plagiarism\PlagiarismCheckService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Factories\PaymentFactory;
use Database\Factories\SubscriptionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/**
 * utf8mb4_unicode_ci makes = / LIKE / UNIQUE case- and accent-insensitive (and PAD SPACE). PostgreSQL was exact.
 * Opaque identifiers are utf8mb4_bin (migration ..._mysql_column_hardening); human text stays _ci ON PURPOSE
 * (sorting, search) with the app-level checks lining up with the unique indexes.
 */
class MySqlCollationTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    private function payment(string $orderId): Payment
    {
        $sub = SubscriptionFactory::new()->create();

        return PaymentFactory::new()->forSubscription($sub)->create(['razorpay_order_id' => $orderId]);
    }

    public function test_razorpay_ids_are_case_sensitive_and_two_case_variants_can_coexist(): void
    {
        $a = $this->payment('order_AbCdEf123456');
        $b = $this->payment('order_abcdef123456'); // would violate the UNIQUE index under utf8mb4_unicode_ci
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($a->id, Payment::query()->where('razorpay_order_id', 'order_AbCdEf123456')->value('id'));
        $this->assertNull(Payment::query()->where('razorpay_order_id', 'ORDER_ABCDEF123456')->first());
        $this->assertSame($b->id, Payment::query()->where('razorpay_order_id', 'order_abcdef123456')->value('id'));
    }

    public function test_webhook_for_a_case_variant_order_id_matches_nothing(): void
    {
        $pay = $this->payment('order_CaseSensitive01');
        app(SubscriptionService::class)->handleWebhookEvent([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_X', 'order_id' => 'order_casesensitive01', 'amount' => 49900, 'currency' => 'INR']]],
        ]);
        $this->assertSame(Payment::CREATED, $pay->refresh()->status, 'a differently-cased order id must not activate the payment');
    }

    public function test_scan_ids_and_token_hashes_are_case_sensitive(): void
    {
        $art = Article::factory()->create();
        $mk = fn (string $scan) => PlagiarismCheckResult::query()->create([
            'article_id' => $art->id, 'provider' => 'COPYLEAKS', 'scan_id' => $scan, 'status' => 'PENDING', 'error_message' => '', 'matches' => [],
        ]);
        $lower = $mk('abcdef0123456789abcdef0123456789');
        $mk('ABCDEF0123456789ABCDEF0123456789'); // must not collide with the lower-case twin
        $this->assertSame('ignored', app(PlagiarismCheckService::class)->handleWebhook('AbCdEf0123456789abcdef0123456789', 'error', []));
        $this->assertSame('failed', app(PlagiarismCheckService::class)->handleWebhook($lower->scan_id, 'error', []));

        $row = fn (string $token) => ['tokenable_type' => User::class, 'tokenable_id' => 1, 'name' => 'x', 'token' => $token, 'abilities' => '["access"]'];
        DB::table('personal_access_tokens')->insert($row(str_repeat('a', 63).'b'));
        DB::table('personal_access_tokens')->insert($row(str_repeat('a', 63).'B'));
        $this->assertSame(1, DB::table('personal_access_tokens')->where('token', str_repeat('a', 63).'B')->count());
    }

    public function test_unique_text_columns_are_ci_and_ai_but_api_reports_a_clean_400_not_a_500(): void
    {
        $admin = User::factory()->admin()->create();
        Tag::factory()->create(['name' => 'Café', 'slug' => 'cafe-tag']);
        foreach (['café', 'CAFÉ', 'cafe', 'Cafe'] as $name) {
            $this->as($admin)->postJson('/api/tags/', ['name' => $name])->assertStatus(400)
                ->assertExactJson(['name' => ['A tag with this name already exists.']]);
        }
        // utf8mb4_unicode_ci gives every non-BMP character (emoji) the same weight: distinct emoji names collide.
        // The pre-check reports it like any duplicate (no unique-violation 500).
        Tag::factory()->create(['name' => '🔥', 'slug' => 'fire-tag']);
        $this->as($admin)->postJson('/api/tags/', ['name' => '🎉'])->assertStatus(400)->assertJsonPath('name.0', 'A tag with this name already exists.');
        // Names that differ in a real letter still coexist, Hindi included.
        $this->as($admin)->postJson('/api/tags/', ['name' => 'भारत'])->assertCreated();
        $this->as($admin)->postJson('/api/tags/', ['name' => 'भारती'])->assertCreated();
        // Rename to itself is not a duplicate of itself.
        $this->as($admin)->patchJson('/api/tags/cafe-tag/', ['name' => 'CAFÉ'])->assertOk();
    }

    public function test_slug_lookup_is_case_insensitive_and_case_variants_cannot_be_created(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $a = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'slug' => 'Mixed-Case-Slug']);
        // Decision: slugs stay _ci. ArticleInput::slugTaken() already forbids case-variant duplicates (LOWER()), so a
        // case-insensitive URL match is unambiguous and friendlier than a 404.
        $this->as(null)->getJson('/api/articles/mixed-case-slug/')->assertOk()->assertJsonPath('id', $a->id);
        $this->as($admin)->postJson('/api/articles/', [
            'title' => 'Other', 'slug' => 'MIXED-case-SLUG', 'content' => '<p>x</p>', 'subcategory_slug' => $t['subcategory']->slug,
        ])->assertStatus(400)->assertJsonPath('slug.0', 'An article with this slug already exists.');
    }

    public function test_login_email_is_case_insensitive_and_trailing_space_is_trimmed(): void
    {
        User::factory()->create(['email' => 'Reader@Example.com']);
        foreach (['reader@example.com', 'READER@EXAMPLE.COM', '  Reader@Example.com  '] as $e) {
            $this->postJson('/api/auth/login/', ['email' => $e, 'password' => 'Sup3r-Secret-pass'])->assertOk();
        }
        $this->postJson('/api/auth/login/', ['email' => 'reader@example.com', 'password' => 'sup3r-secret-pass'])->assertStatus(401);
        // a second account differing only by case is refused (the DB unique index is _ci as well)
        $this->postJson('/api/auth/register/', ['email' => 'READER@example.com', 'password' => 'Str0ng-Passw0rd-xyz', 'password2' => 'Str0ng-Passw0rd-xyz'])
            ->assertStatus(400)->assertJsonPath('email.0', 'An account with this email already exists.');
    }

    public function test_like_escaping_of_backslash_percent_and_underscore_in_all_search_boxes(): void
    {
        $t = $this->tree();
        $mk = fn (string $title) => Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => $title, 'excerpt' => 'x', 'content' => '<p>body</p>']);
        $pct = $mk('Growth hit 100% today');
        $und = $mk('The real_deal arrives');
        $bs = $mk('Path C:\\temp\\file');
        $mk('Growth hit 1000 today');
        $mk('The realXdeal arrives');
        $mk('Path C:/temp/file');

        $ids = fn (string $q) => array_column($this->as(null)->getJson('/api/articles/?search='.urlencode($q))->assertOk()->json('results'), 'id');
        $this->assertSame([$pct->id], $ids('100%'));
        $this->assertSame([$und->id], $ids('real_deal'));
        $this->assertSame([$bs->id], $ids('C:\\temp'));
        // A lone wildcard is a LITERAL: it matches only titles that really contain it, never everything.
        $this->assertSame([$pct->id], $ids('%'));
        $this->assertSame([$und->id], $ids('_'));
        $this->assertSame([$bs->id], $ids('\\'));
        $this->assertSame([], $ids('\\\\'), 'two backslashes are not in any title');
        // admin lists share the same escaping (ArticleListQuery via /articles/mine/)
        $rep = User::factory()->reporter()->create();
        Article::query()->update(['author_id' => $rep->id]);
        $mine = array_column($this->as($rep)->getJson('/api/articles/mine/?search='.urlencode('100%'))->assertOk()->json('results'), 'id');
        $this->assertSame([$pct->id], $mine);
        $this->assertSame([$und->id], array_column($this->as($rep)->getJson('/api/articles/mine/?search='.urlencode('_'))->json('results'), 'id'));
        // tags / accounts search boxes
        Tag::factory()->create(['name' => 'a_b', 'slug' => 'a-b']);
        Tag::factory()->create(['name' => 'axb', 'slug' => 'axb']);
        $this->assertSame(['a-b'], array_column($this->as(null)->getJson('/api/tags/?search='.urlencode('a_b'))->assertOk()->json(), 'slug'));
    }

    public function test_search_is_case_and_accent_insensitive_hindi_works_and_trailing_space_pads(): void
    {
        $t = $this->tree();
        $a = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Café culture in Mumbai', 'excerpt' => 'x']);
        $h = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'भारत में चुनाव', 'excerpt' => 'x']);
        $ids = fn (string $q) => array_column($this->as(null)->getJson('/api/articles/?search='.urlencode($q))->assertOk()->json('results'), 'id');
        $this->assertSame([$a->id], $ids('CAFE'));
        $this->assertSame([$a->id], $ids('café'));
        $this->assertSame([$h->id], $ids('चुनाव'));
        $this->assertSame([$h->id], $ids('भारत'));
        // PAD SPACE: 'abc ' = 'abc' in every MySQL collation - documented behaviour, inputs are trimmed before use.
        $this->assertSame(1, DB::selectOne("SELECT 'abc ' = 'abc' AS eq")->eq);
        $this->assertSame(0, DB::selectOne("SELECT 'abc ' LIKE 'abc' AS eq")->eq);
    }
}
