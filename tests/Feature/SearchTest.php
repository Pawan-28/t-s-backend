<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Subcategory;
use App\Models\Tag;
use App\Models\User;
use App\Services\Search\ArticleSearchIndexer;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use ArticleTestHelpers, DatabaseTruncation; // InnoDB FULLTEXT only sees COMMITTED rows, so no wrapping transaction here

    protected function tearDown(): void
    {
        // Truncation runs only BEFORE each test; without this the last test's committed rows leak into
        // every later RefreshDatabase test of the suite (MySQL 8 exposes this as taxonomy/count failures).
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function art(array $x = [], ?Subcategory $s = null): Article
    {
        $s ??= $this->tree()['subcategory'];

        return Article::factory()->published()->create(['subcategory_id' => $s->id] + $x);
    }

    private function titles(string $qs, ?User $u = null): array
    {
        return array_column($this->as($u)->getJson('/api/search/?'.$qs)->assertOk()->json('results'), 'title');
    }

    public function test_only_published_articles_are_searchable_by_everyone_including_admin(): void
    {
        $t = $this->tree();
        $this->art(['title' => 'Pineapple pub'], $t['subcategory']);
        foreach ([ArticleStatus::DRAFT, ArticleStatus::SUBMITTED, ArticleStatus::UNDER_REVIEW, ArticleStatus::APPROVED, ArticleStatus::SCHEDULED, ArticleStatus::REJECTED, ArticleStatus::CHANGES_REQUESTED] as $s) {
            Article::factory()->status($s)->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Pineapple '.$s->value]);
        }
        foreach ([null, User::factory()->create(), User::factory()->reporter()->create(), User::factory()->admin()->create()] as $u) {
            $this->assertSame(['Pineapple pub'], $this->titles('q=pineapple', $u));
        }
    }

    public function test_no_query_and_no_filter_returns_an_empty_page_and_blank_query_too(): void
    {
        $this->art(['title' => 'Something']);
        foreach (['', 'q=', 'q=%20%20', 'page=1'] as $qs) {
            $this->as(null)->getJson('/api/search/?'.$qs)->assertOk()->assertExactJson(['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
        }
        $this->as(null)->getJson('/api/search')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_filter_only_browses_newest_first(): void
    {
        $t = $this->tree(subcategory: ['slug' => 'browse-me']);
        $this->art(['title' => 'old', 'published_at' => now()->subDays(3)], $t['subcategory']);
        $this->art(['title' => 'new', 'published_at' => now()->subDay()], $t['subcategory']);
        $this->art(['title' => 'elsewhere']);
        $this->assertSame(['new', 'old'], $this->titles('subcategory=browse-me'));
    }

    public function test_relevance_orders_title_over_excerpt_over_body_then_recency(): void
    {
        $t = $this->tree();
        $mk = fn (array $x) => $this->art($x, $t['subcategory']);
        $mk(['title' => 'Quantum computing breakthrough', 'excerpt' => 'x', 'content' => '<p>filler</p>', 'published_at' => now()->subDays(9)]);
        $mk(['title' => 'Daily digest', 'excerpt' => 'Quantum tidbits', 'content' => '<p>filler</p>', 'published_at' => now()->subDays(1)]);
        $mk(['title' => 'Weekend reading', 'excerpt' => 'x', 'content' => '<p>a note about quantum things</p>', 'published_at' => now()->subDays(1)]);
        $this->assertSame(['Quantum computing breakthrough', 'Daily digest', 'Weekend reading'], $this->titles('q=quantum'));

        // equal relevance -> newest first
        $mk(['title' => 'Zeta gadget', 'published_at' => now()->subDays(5)]);
        $mk(['title' => 'Zeta gadget', 'published_at' => now()->subDays(2)]);
        $r = $this->as(null)->getJson('/api/search/?q=zeta')->json('results');
        $this->assertGreaterThan($r[1]['published_at'], $r[0]['published_at']);
    }

    public function test_websearch_syntax_phrases_or_and_exclusion(): void
    {
        $t = $this->tree();
        $mk = fn (string $title) => $this->art(['title' => $title, 'excerpt' => '', 'content' => '<p>filler words</p>'], $t['subcategory']);
        $mk('red fox jumps');
        $mk('fox red jumps');
        $mk('blue whale sings');
        $this->assertEqualsCanonicalizing(['red fox jumps', 'fox red jumps'], $this->titles('q='.urlencode('red fox')));
        $this->assertSame(['red fox jumps'], $this->titles('q='.urlencode('"red fox"')));
        $this->assertEqualsCanonicalizing(['red fox jumps', 'fox red jumps', 'blue whale sings'], $this->titles('q='.urlencode('fox or whale')));
        $this->assertSame(['fox red jumps'], $this->titles('q='.urlencode('fox -"red fox"')));
        $this->assertSame([], $this->titles('q='.urlencode('fox -jumps')));
    }

    public function test_english_stemming_matches_word_forms(): void
    {
        $this->art(['title' => 'Runners racing through markets']);
        $this->assertSame(['Runners racing through markets'], $this->titles('q=runner'));
        $this->assertSame(['Runners racing through markets'], $this->titles('q=market'));
    }

    public function test_hostile_query_strings_are_safe(): void
    {
        $this->art(['title' => 'Safe article']);
        foreach (["'; DROP TABLE articles; --", 'a & b | !c', '(((', ':*', '\\', '"unterminated', 'a:b:c', "\x00null", '%', '_', str_repeat('word ', 400), 'नमस्ते दुनिया', '<script>alert(1)</script>', "' OR '1'='1"] as $q) {
            $this->as(null)->getJson('/api/search/?q='.urlencode($q))->assertOk()->assertJsonStructure(['count', 'next', 'previous', 'results']);
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('articles'));
        $this->assertSame(1, Article::count());
        // array-valued params are treated as absent
        $this->as(null)->getJson('/api/search/?q[]=safe')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_taxonomy_and_tag_filters_with_query_and_precedence(): void
    {
        $a = $this->tree(industry: ['slug' => 'ia'], category: ['slug' => 'ca'], subcategory: ['slug' => 'sa']);
        $b = $this->tree(industry: ['slug' => 'ib'], category: ['slug' => 'cb'], subcategory: ['slug' => 'sb']);
        $tag = Tag::factory()->create(['slug' => 'hot', 'name' => 'Hot']);
        $x = $this->art(['title' => 'Rocket alpha'], $a['subcategory']);
        $x->tags()->sync([$tag->id]);
        $this->art(['title' => 'Rocket beta'], $b['subcategory']);
        $this->assertEqualsCanonicalizing(['Rocket alpha', 'Rocket beta'], $this->titles('q=rocket'));
        $this->assertSame(['Rocket alpha'], $this->titles('q=rocket&subcategory=sa'));
        $this->assertSame(['Rocket alpha'], $this->titles('q=rocket&category=ca'));
        $this->assertSame(['Rocket beta'], $this->titles('q=rocket&industry=ib'));
        $this->assertSame(['Rocket alpha'], $this->titles('q=rocket&tag=hot'));
        $this->assertSame(['Rocket alpha'], $this->titles('tag=hot'));
        $this->assertSame([], $this->titles('q=rocket&tag=hot&category=cb'));
        $this->assertSame(['Rocket alpha'], $this->titles('q=rocket&subcategory=sa&category=cb&industry=ib'), 'subcategory wins');
        $this->assertSame(['Rocket beta'], $this->titles('q=rocket&category=cb&industry=ia'), 'category beats industry');
    }

    public function test_legacy_article_without_subcategory_is_found_by_category_and_industry_filters(): void
    {
        $t = $this->tree(industry: ['slug' => 'li'], category: ['slug' => 'lc', 'name' => 'Legacycatname']);
        Article::factory()->published()->create(['subcategory_id' => null, 'category_id' => $t['category']->id, 'title' => 'Legacy walrus']);
        $this->assertSame(['Legacy walrus'], $this->titles('q=walrus&category=lc'));
        $this->assertSame(['Legacy walrus'], $this->titles('q=walrus&industry=li'));
        $this->assertSame(['Legacy walrus'], $this->titles('q=legacycatname'), 'category name is indexed for legacy rows too');
    }

    public function test_taxonomy_and_tag_names_are_searchable_even_when_absent_from_the_text(): void
    {
        $t = $this->tree(industry: ['name' => 'Aerospace Industry'], category: ['name' => 'Rocketry Category'], subcategory: ['name' => 'Thrusters Subcategory']);
        $tag = Tag::factory()->create(['name' => 'Nebulatag', 'slug' => 'nebulatag']);
        $a = $this->art(['title' => 'Plain title', 'excerpt' => '', 'content' => '<p>Plain body</p>'], $t['subcategory']);
        $a->tags()->sync([$tag->id]);
        app(ArticleSearchIndexer::class)->refresh($a);
        foreach (['aerospace', 'rocketry', 'thrusters', 'nebulatag'] as $word) {
            $this->assertSame(['Plain title'], $this->titles('q='.$word), $word);
        }
    }

    public function test_html_tags_are_not_indexed_but_body_text_is(): void
    {
        $this->art(['title' => 'Body test', 'content' => '<p class="zzzclass">visible <strong>marmoset</strong></p><img src="https://x.test/hidden.png" alt="a">']);
        $this->assertSame(['Body test'], $this->titles('q=marmoset'));
        $this->assertSame([], $this->titles('q=zzzclass'));
        $this->assertSame([], $this->titles('q=strong'));
    }

    public function test_pagination_page_size_default_cap_and_invalid_page(): void
    {
        $t = $this->tree();
        foreach (range(1, 55) as $i) {
            $this->art(['title' => "Common word {$i}"], $t['subcategory']);
        }
        $r = $this->as(null)->getJson('/api/search/?q=common')->assertOk()->assertJsonPath('count', 55);
        $this->assertCount(20, $r->json('results'));
        $this->assertStringContainsString('page=2', $r->json('next'));
        $this->assertCount(5, $this->as(null)->getJson('/api/search/?q=common&page_size=5')->json('results'));
        $this->assertCount(50, $this->as(null)->getJson('/api/search/?q=common&page_size=500')->json('results'));
        $this->assertCount(20, $this->as(null)->getJson('/api/search/?q=common&page_size=abc')->json('results'));
        $this->assertCount(20, $this->as(null)->getJson('/api/search/?q=common&page_size=0')->json('results'));
        $this->assertCount(5, $this->as(null)->getJson('/api/search/?q=common&page=2&page_size=50')->json('results'));
        $this->as(null)->getJson('/api/search/?q=common&page=9')->assertStatus(404)->assertExactJson(['detail' => 'Invalid page.']);
    }

    public function test_page_size_cap_follows_config(): void
    {
        config(['portal.search_page_size_max' => 3]);
        $t = $this->tree();
        foreach (range(1, 6) as $i) {
            $this->art(['title' => "Common word {$i}"], $t['subcategory']);
        }
        $this->assertCount(3, $this->as(null)->getJson('/api/search/?q=common&page_size=50')->json('results'));
    }

    public function test_results_use_the_article_shape_and_search_survives_a_taxonomy_rename(): void
    {
        $t = $this->tree(category: ['name' => 'Old Name Category', 'slug' => 'renamed-cat']);
        $this->art(['title' => 'Alone'], $t['subcategory']);
        $item = $this->as(null)->getJson('/api/search/?q=alone')->json('results.0');
        $this->assertSame($t['category']->id, $item['category']['id']);
        $this->assertArrayHasKey('is_locked', $item);
        $this->assertSame(['Alone'], $this->titles('q=old'));
        $this->as(User::factory()->admin()->create())->patchJson('/api/categories/renamed-cat/', ['name' => 'Fresh Label'])->assertOk();
        $this->drainQueue();   // ReindexArticleSearch runs inline with sync, from the cron worker otherwise
        $this->assertSame([], $this->titles('q=old'));
        $this->assertSame(['Alone'], $this->titles('q=fresh'));
    }

    public function test_reindex_command_backfills_missing_vectors(): void
    {
        $a = $this->art(['title' => 'Backfill kangaroo']);
        $b = $this->art(['title' => 'Backfill koala']);
        DB::table('article_search_index')->delete();
        $this->assertSame([], $this->titles('q=kangaroo'));
        $this->artisan('articles:reindex-search')->expectsOutputToContain('Reindexed 2 article')->assertSuccessful();
        $this->assertSame(['Backfill kangaroo'], $this->titles('q=kangaroo'));
        $this->assertSame(['Backfill koala'], $this->titles('q=koala'));
        $this->assertNotNull($a->id.$b->id);
    }

    public function test_status_change_does_not_hide_or_expose_wrongly(): void
    {
        $t = $this->tree();
        $a = Article::factory()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Draft dolphin']);
        $this->assertSame([], $this->titles('q=dolphin'));
        $a->forceFill(['status' => ArticleStatus::PUBLISHED, 'published_at' => now()])->save();
        $this->assertSame(['Draft dolphin'], $this->titles('q=dolphin'), 'vector existed since creation');
    }
}
