<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use App\Services\Search\ArticleSearchIndexer;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/**
 * Emoji (4-byte utf8mb4), Devanagari and very large bodies end to end: API write -> sanitizer -> LONGTEXT/JSON ->
 * API read -> FULLTEXT / LIKE search. DatabaseTruncation: InnoDB FULLTEXT only sees committed rows.
 */
class MySqlUnicodeEndToEndTest extends TestCase
{
    use ArticleTestHelpers, DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function publish(User $admin, array $body, array $tree): array
    {
        $created = $this->as($admin)->postJson('/api/articles/', $body + ['subcategory_slug' => $tree['subcategory']->slug])->assertCreated();
        $this->as($admin)->postJson('/api/articles/'.$created->json('slug').'/publish/')->assertOk();

        return $created->json();
    }

    public function test_emoji_and_hindi_article_round_trips_through_sanitizer_json_and_search(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $faqs = [['question' => 'क्या यह सही है? 🤔', 'answer' => 'हाँ, बिल्कुल ✅ "quotes" & <b>x</b> \\ /slash'], ['question' => 'Second 😀', 'answer' => 'ठीक है']];
        $content = '<p>नमस्ते 🌍 <strong>दुनिया</strong> &amp; चुनाव के नतीजे</p><script>alert("🔥")</script><p>😀😃😄</p>';
        $created = $this->publish($admin, [
            'title' => 'चुनाव 2026 😀 results', 'excerpt' => 'सारांश 🎉 excerpt', 'content' => $content, 'location_name' => 'मुंबई 📍', 'faqs' => $faqs,
        ], $t);

        $detail = $this->as(null)->getJson('/api/articles/'.$created['slug'].'/')->assertOk()->json();
        $this->assertSame('चुनाव 2026 😀 results', $detail['title']);
        $this->assertSame('सारांश 🎉 excerpt', $detail['excerpt']);
        $this->assertSame('मुंबई 📍', $detail['location_name']);
        $this->assertStringContainsString('नमस्ते 🌍 <strong>दुनिया</strong>', $detail['content']);
        $this->assertStringContainsString('😀😃😄', $detail['content']);
        $this->assertStringNotContainsString('<script', $detail['content']);
        $this->assertStringNotContainsString('alert', $detail['content']);
        $this->assertSame(array_map(fn ($f) => ['question' => $f['question'], 'answer' => strip_tags($f['answer'])], $faqs), array_map(fn ($f) => ['question' => $f['question'], 'answer' => $f['answer']], $detail['faqs']));

        // raw storage: valid JSON, real UTF-8 (never lossy '????'), same list on both engines
        $raw = DB::table('articles')->where('id', $created['id'])->first();
        $decoded = json_decode($raw->faqs, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $decoded);
        $this->assertSame($detail['faqs'], $decoded);
        $this->assertSame($detail['content'], $raw->content);
        $this->assertSame(bin2hex('😀'), bin2hex(mb_substr($raw->title, mb_strpos($raw->title, '😀'), 1)));
        $this->assertStringNotContainsString('?', $raw->title, 'no lossy conversion to ?');

        foreach (['चुनाव', 'नतीजे', 'results', 'RESULTS', 'चुनाव results', 'मुंबई', 'सारांश'] as $q) {
            $found = array_column($this->as(null)->getJson('/api/search/?q='.urlencode($q))->assertOk()->json('results'), 'id');
            $this->assertSame([$created['id']], $found, "search '{$q}'");
        }
        // emoji-only / symbol queries never error (nothing searchable in them)
        foreach (['😀', '🔥🔥', '%', '"', '-', '+*<>()~@'] as $q) {
            $this->as(null)->getJson('/api/search/?q='.urlencode($q))->assertOk();
        }
        $this->assertSame([$created['id']], array_column($this->as(null)->getJson('/api/articles/?search='.urlencode('😀'))->assertOk()->json('results'), 'id'), 'LIKE search finds the emoji title');
    }

    public function test_very_large_bodies_are_stored_read_back_and_indexed(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $para = '<p>'.str_repeat('Lorem ipsum dolor sit amet, संविधान का पालन। ', 20).'</p>';
        $ascii = str_repeat($para, (int) ceil(1_900_000 / mb_strlen($para))); // ~1.9M characters, LONGTEXT >> 64 KB
        $ascii = mb_substr($ascii, 0, 1_900_000);
        $created = $this->publish($admin, ['title' => 'Huge body', 'content' => $ascii], $t);

        $back = DB::table('articles')->where('id', $created['id'])->value('content');
        $this->assertGreaterThan(65535, strlen($back));
        $this->assertGreaterThan(1_800_000, mb_strlen($back));
        $api = $this->as(null)->getJson('/api/articles/'.$created['slug'].'/')->assertOk()->json('content');
        $this->assertSame($back, $api);
        $this->assertTrue(str_ends_with($api, '</p>') || mb_strlen($api) > 1_800_000);
        $this->assertSame(['Huge body'], array_column($this->as(null)->getJson('/api/search/?q=Huge')->json('results'), 'title'));
        $this->assertSame([$created['id']], array_column($this->as(null)->getJson('/api/search/?q=संविधान')->json('results'), 'id'));
        $body = DB::table('article_search_index')->where('article_id', $created['id'])->value('body');
        $this->assertLessThanOrEqual(400000, mb_strlen($body), 'index body is bounded');
    }

    public function test_largest_legal_request_of_four_byte_characters_fits_max_allowed_packet(): void
    {
        $packet = (int) DB::selectOne('SELECT @@max_allowed_packet AS p')->p;
        if ($packet < 16 * 1024 * 1024) {
            $this->markTestSkipped("max_allowed_packet is {$packet}: raise it to >= 16M for the worst-case article (see portal:doctor)");
        }
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $content = '<p>'.str_repeat('😀', 1_999_000).'</p>';           // ~8 MB, limit is 2,000,000 characters
        $excerpt = str_repeat('🎉', 1_000_000);                         // 4 MB, the limit
        $r = $this->as($admin)->postJson('/api/articles/', ['title' => 'Emoji flood', 'content' => $content, 'excerpt' => $excerpt, 'subcategory_slug' => $t['subcategory']->slug]);
        $r->assertCreated();
        $row = DB::table('articles')->where('id', $r->json('id'))->first(['content', 'excerpt']);
        $this->assertSame(1_000_000, mb_strlen($row->excerpt));
        $this->assertGreaterThan(1_998_000, mb_strlen($row->content));
        $this->assertSame(1_000_000, mb_strlen(Article::query()->findOrFail($r->json('id'))->excerpt));
        $this->as($admin)->postJson('/api/articles/', ['title' => 'Over the limit', 'content' => '<p>'.str_repeat('a', 2_000_001).'</p>', 'subcategory_slug' => $t['subcategory']->slug])->assertStatus(400);
        $this->assertNotNull(app(ArticleSearchIndexer::class));
    }
}
