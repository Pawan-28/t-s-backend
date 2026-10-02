<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

class ArticlesListTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    public function test_envelope_page_size_ordering_and_trailing_slash(): void
    {
        $t = $this->tree();
        foreach (range(1, 25) as $i) {
            Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => "A{$i}", 'created_at' => now()->subMinutes(100 - $i), 'published_at' => now()->subMinutes(100 - $i)]);
        }
        foreach (['/api/articles/', '/api/articles'] as $url) {
            $r = $this->as(null)->getJson($url)->assertOk()->assertJsonStructure(['count', 'next', 'previous', 'results'])->assertJsonPath('count', 25)->assertJsonPath('previous', null);
            $this->assertCount(20, $r->json('results'));
            $this->assertSame('A25', $r->json('results.0.title'), 'newest first by default');
        }
        $p2 = $this->as(null)->getJson('/api/articles/?page=2')->assertOk();
        $this->assertCount(5, $p2->json('results'));
        $this->assertNull($p2->json('next'));
        $this->assertStringContainsString('/api/articles', $p2->json('previous'));
        $this->assertStringContainsString('page=2', $this->as(null)->getJson('/api/articles/')->json('next'));
        $this->as(null)->getJson('/api/articles/?page=3')->assertStatus(404)->assertExactJson(['detail' => 'Invalid page.']);
        $this->as(null)->getJson('/api/articles/?page=abc')->assertStatus(404);
        // ?page_size is ignored, exactly like Django's PageNumberPagination
        $this->assertCount(20, $this->as(null)->getJson('/api/articles/?page_size=5')->json('results'));
        $asc = $this->as(null)->getJson('/api/articles/?ordering=created_at')->json('results.0.title');
        $this->assertSame('A1', $asc);
        $this->assertSame('A25', $this->as(null)->getJson('/api/articles/?ordering=-published_at')->json('results.0.title'));
        $this->assertSame('A25', $this->as(null)->getJson('/api/articles/?ordering=title')->json('results.0.title'), 'unknown ordering field (title) is ignored -> default');
    }

    public function test_item_shape_matches_django_serializer(): void
    {
        $t = $this->tree();
        Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id]);
        $item = $this->as(null)->getJson('/api/articles/')->json('results.0');
        foreach (['id', 'title', 'slug', 'excerpt', 'content', 'location_name', 'faqs', 'subcategory', 'category', 'industry', 'tags', 'author', 'assigned_reporter', 'status', 'rejection_reason', 'access_level', 'featured_image_url', 'is_locked', 'scheduled_publish_at', 'published_at', 'created_at', 'updated_at'] as $k) {
            $this->assertArrayHasKey($k, $item, $k);
        }
        $this->assertSame($t['category']->slug, $item['subcategory']['category']['slug']);
        $this->assertSame($t['industry']->slug, $item['subcategory']['category']['industry']['slug']);
        $this->assertSame(['id', 'email', 'first_name', 'last_name', 'full_name'], array_keys($item['author']));
    }

    public function test_visibility_by_role(): void
    {
        $t = $this->tree();
        $me = User::factory()->reporter()->create();
        $other = User::factory()->reporter()->create();
        $mk = fn (ArticleStatus $s, array $x = []) => Article::factory()->status($s)->create(['subcategory_id' => $t['subcategory']->id, 'published_at' => $s === ArticleStatus::PUBLISHED ? now() : null] + $x);
        $mk(ArticleStatus::PUBLISHED, ['title' => 'pub']);
        $mk(ArticleStatus::DRAFT, ['author_id' => $me->id, 'title' => 'my-draft']);
        $mk(ArticleStatus::DRAFT, ['author_id' => $other->id, 'title' => 'their-draft']);
        $mk(ArticleStatus::UNDER_REVIEW, ['author_id' => $other->id, 'assigned_reporter_id' => $me->id, 'title' => 'assigned']);
        $mk(ArticleStatus::SUBMITTED, ['author_id' => $other->id, 'title' => 'submitted']);

        $titles = fn (?User $u) => collect($this->as($u)->getJson('/api/articles/')->assertOk()->json('results'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['pub'], $titles(null));
        $this->assertSame(['pub'], $titles(User::factory()->create()));
        $this->assertSame(['pub'], $titles(User::factory()->subscriber()->create()));
        $this->assertSame(['assigned', 'my-draft', 'pub'], $titles($me));
        $this->assertSame(['assigned', 'my-draft', 'pub', 'submitted', 'their-draft'], $titles(User::factory()->admin()->create()));
    }

    public function test_status_access_level_author_and_assigned_reporter_filters(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $a1 = User::factory()->reporter()->create();
        $a2 = User::factory()->reporter()->create();
        Article::factory()->create(['author_id' => $a1->id, 'subcategory_id' => $t['subcategory']->id, 'title' => 'd1']);
        Article::factory()->published()->create(['author_id' => $a2->id, 'subcategory_id' => $t['subcategory']->id, 'title' => 'p2', 'access_level' => AccessLevel::RESTRICTED]);
        Article::factory()->status(ArticleStatus::UNDER_REVIEW)->create(['author_id' => $a2->id, 'assigned_reporter_id' => $a1->id, 'subcategory_id' => $t['subcategory']->id, 'title' => 'ur']);

        $q = fn (string $qs) => collect($this->as($admin)->getJson("/api/articles/?{$qs}")->assertOk()->json('results'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['d1'], $q('status=DRAFT'));
        $this->assertSame(['p2'], $q('status=PUBLISHED'));
        $this->assertSame(['p2'], $q('access_level=RESTRICTED'));
        $this->assertSame(['d1'], $q('author='.$a1->id));
        $this->assertSame(['p2', 'ur'], $q('author='.$a2->id));
        $this->assertSame(['ur'], $q('assigned_reporter='.$a1->id));
        $this->assertSame(['d1', 'p2', 'ur'], $q('status='));
        $this->as($admin)->getJson('/api/articles/?status=BOGUS')->assertStatus(400)->assertExactJson(['status' => ['Select a valid choice. BOGUS is not one of the available choices.']]);
        $this->as($admin)->getJson('/api/articles/?author=abc')->assertStatus(400)->assertJsonStructure(['author']);
        $this->as($admin)->getJson('/api/articles/?author=999999')->assertStatus(400)->assertJsonStructure(['author']);
        // a public caller's status filter is still confined to PUBLISHED
        $this->assertSame([], collect($this->as(null)->getJson('/api/articles/?status=DRAFT')->json('results'))->all());
    }

    public function test_search_param_is_icontains_over_title_excerpt_content_with_and_between_terms(): void
    {
        $t = $this->tree();
        Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Alpha Rocket', 'excerpt' => 'one', 'content' => '<p>launch pad</p>']);
        Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Beta', 'excerpt' => 'ROCKET fuel', 'content' => '<p>nothing</p>']);
        Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Gamma', 'excerpt' => 'two', 'content' => '<p>a Rocket engine, launch</p>']);
        $n = fn (string $s) => $this->as(null)->getJson('/api/articles/?search='.urlencode($s))->assertOk()->json('count');
        $this->assertSame(3, $n('rocket'));
        $this->assertSame(2, $n('rocket launch'));
        $this->assertSame(2, $n('rocket,launch'));
        $this->assertSame(0, $n('100%'));
        $this->assertSame(0, $n("' OR 1=1 --"));
    }

    public function test_taxonomy_filters_with_precedence_subcategory_over_category_over_industry(): void
    {
        $a = $this->tree(industry: ['slug' => 'ind-a'], category: ['slug' => 'cat-a'], subcategory: ['slug' => 'sub-a']);
        $b = $this->tree(industry: ['slug' => 'ind-b'], category: ['slug' => 'cat-b'], subcategory: ['slug' => 'sub-b']);
        $a2 = Subcategory::factory()->create(['category_id' => $a['category']->id, 'slug' => 'sub-a2']);
        $mk = fn (Subcategory $s, string $title) => Article::factory()->published()->create(['subcategory_id' => $s->id, 'title' => $title]);
        $mk($a['subcategory'], 'A-1');
        $mk($a2, 'A-2');
        $mk($b['subcategory'], 'B-1');
        $t = fn (string $qs) => collect($this->as(null)->getJson("/api/articles/?{$qs}")->json('results'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['A-1'], $t('subcategory=sub-a'));
        $this->assertSame(['A-1', 'A-2'], $t('category=cat-a'));
        $this->assertSame(['A-1', 'A-2'], $t('industry=ind-a'));
        $this->assertSame(['B-1'], $t('industry=ind-b'));
        // precedence: subcategory wins over category and industry; category wins over industry
        $this->assertSame(['A-1'], $t('subcategory=sub-a&category=cat-b&industry=ind-b'));
        $this->assertSame(['B-1'], $t('category=cat-b&industry=ind-a'));
        $this->assertSame([], $t('category=missing'));
    }

    public function test_legacy_articles_without_subcategory_match_via_their_category_and_industry(): void
    {
        $t = $this->tree(industry: ['slug' => 'leg-ind'], category: ['slug' => 'leg-cat']);
        $legacy = Article::factory()->published()->create(['subcategory_id' => null, 'category_id' => $t['category']->id, 'title' => 'legacy']);
        $this->assertNull($legacy->fresh()->subcategory_id);
        $this->assertSame(['legacy'], collect($this->as(null)->getJson('/api/articles/?category=leg-cat')->json('results'))->pluck('title')->all());
        $this->assertSame(['legacy'], collect($this->as(null)->getJson('/api/articles/?industry=leg-ind')->json('results'))->pluck('title')->all());
        $this->assertSame([], $this->as(null)->getJson('/api/articles/?subcategory=leg-cat')->json('results'));
        $r = $this->as(null)->getJson('/api/articles/'.$legacy->slug.'/')->assertOk();
        $r->assertJsonPath('subcategory', null)->assertJsonPath('category.slug', 'leg-cat')->assertJsonPath('industry.slug', 'leg-ind');
    }

    public function test_listing_runs_a_constant_number_of_queries(): void
    {
        $t = $this->tree();
        Article::factory()->count(3)->published()->create(['subcategory_id' => $t['subcategory']->id]);
        $count = function () {
            $this->as(null);
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->getJson('/api/articles/')->assertOk();

            // Rate-limit bookkeeping on the `database` cache store is not part of the listing's own query budget.
            return count(array_filter(\DB::getQueryLog(), fn ($q) => ! str_contains($q['query'], '`cache`')));
        };
        $small = $count();
        Article::factory()->count(15)->published()->create(['subcategory_id' => $this->tree()['subcategory']->id]);
        $this->assertSame($small, $count(), 'no N+1: query count is independent of the number of articles');
    }
}
