<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleDailyView;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function views(Article $a, string $date, int $n): void
    {
        ArticleDailyView::create(['article_id' => $a->id, 'date' => $date, 'views' => $n]);
    }

    /** @return array{industry: Industry, cat: Category, sub: Subcategory, a1: Article, a2: Article, draft: Article} */
    private function world(): array
    {
        $industry = Industry::factory()->create(['name' => 'Tech']);
        $cat = Category::factory()->create(['industry_id' => $industry->id, 'name' => 'Gadgets']);
        $sub = Subcategory::factory()->create(['category_id' => $cat->id, 'name' => 'Phones']);
        $a1 = Article::factory()->published()->create(['subcategory_id' => $sub->id, 'title' => 'Alpha phone launch', 'slug' => 'alpha-phone']);
        $a2 = Article::factory()->published()->create(['subcategory_id' => $sub->id, 'title' => 'Beta tablet', 'slug' => 'beta-tablet']);
        $draft = Article::factory()->create(['subcategory_id' => $sub->id]);
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $this->views($a1, $today, 10);
        $this->views($a1, $yesterday, 5);
        $this->views($a2, $today, 20);
        $this->views($draft, $today, 1000);   // unpublished: must never be counted

        return compact('industry', 'cat', 'sub', 'a1', 'a2', 'draft');
    }

    public function test_every_admin_endpoint_requires_admin(): void
    {
        $paths = ['overview', 'articles/popular', 'industries', 'categories', 'subcategories', 'reporters', 'publishing', 'views-over-time', 'admin/article-daily-views'];
        foreach ($paths as $p) {
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/analytics/{$p}/")->assertUnauthorized();
            foreach ([User::factory()->reporter()->create(), User::factory()->subscriber()->create()] as $u) {
                $this->app['auth']->forgetGuards();
                $this->actingAsUser($u)->getJson("/api/analytics/{$p}/")->assertForbidden();
            }
            $this->app['auth']->forgetGuards();
            $this->actingAsUser($this->admin)->getJson("/api/analytics/{$p}/")->assertOk();
        }
    }

    public function test_empty_database_yields_zeros_and_empty_lists(): void
    {
        $this->actingAsUser($this->admin);
        $this->getJson('/api/analytics/overview/')->assertOk()->assertExactJson(['total_views' => 0]);
        foreach (['articles/popular', 'industries', 'categories', 'subcategories', 'views-over-time'] as $p) {
            $this->getJson("/api/analytics/{$p}/")->assertOk()->assertExactJson([]);
        }
        $this->getJson('/api/analytics/publishing/')->assertOk()->assertExactJson(['published_count' => 0, 'scheduled_count' => 0, 'published_last_n_days' => []]);
        $this->getJson('/api/analytics/admin/article-daily-views/')->assertOk()->assertExactJson(['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
    }

    public function test_overview_counts_only_published_articles(): void
    {
        $this->world();
        $this->actingAsUser($this->admin)->getJson('/api/analytics/overview/')->assertOk()->assertExactJson(['total_views' => 35]);
    }

    public function test_popular_articles_shape_order_and_limit(): void
    {
        $w = $this->world();
        $none = Article::factory()->published()->create(['subcategory_id' => $w['sub']->id, 'published_at' => now()->subDays(3)]);

        $rows = $this->actingAsUser($this->admin)->getJson('/api/analytics/articles/popular/')->assertOk()->json();

        $this->assertSame([$w['a2']->id, $w['a1']->id, $none->id], array_column($rows, 'id'));
        $this->assertSame([20, 15, 0], array_column($rows, 'total_views'));
        $this->assertSame(['id', 'title', 'slug', 'total_views', 'category', 'industry', 'published_at'], array_keys($rows[0]));
        $this->assertSame(['id' => $w['cat']->id, 'name' => 'Gadgets', 'slug' => $w['cat']->slug], $rows[0]['category']);
        $this->assertSame(['id' => $w['industry']->id, 'name' => 'Tech', 'slug' => $w['industry']->slug], $rows[0]['industry']);

        $this->getJson('/api/analytics/articles/popular/?limit=1')->assertJsonCount(1);
        $this->getJson('/api/analytics/articles/popular/?limit=0')->assertJsonCount(1);      // clamped to 1
        $this->getJson('/api/analytics/articles/popular/?limit=abc')->assertOk()->assertJsonCount(3);
    }

    public function test_taxonomy_breakdowns(): void
    {
        $w = $this->world();
        // a second industry with a published article that has no views: appears with 0
        $sub2 = Subcategory::factory()->create();
        Article::factory()->published()->create(['subcategory_id' => $sub2->id]);

        $this->actingAsUser($this->admin);
        $ind = $this->getJson('/api/analytics/industries/')->assertOk()->json();
        $this->assertSame(['industry_id' => $w['industry']->id, 'name' => 'Tech', 'slug' => $w['industry']->slug, 'total_views' => 35], $ind[0]);
        $this->assertSame(0, $ind[1]['total_views']);
        $this->assertCount(2, $ind);

        $cat = $this->getJson('/api/analytics/categories/')->assertOk()->json();
        $this->assertSame(['category_id' => $w['cat']->id, 'name' => 'Gadgets', 'slug' => $w['cat']->slug, 'industry' => 'Tech', 'total_views' => 35], $cat[0]);

        $sub = $this->getJson('/api/analytics/subcategories/')->assertOk()->json();
        $this->assertSame(['subcategory_id' => $w['sub']->id, 'name' => 'Phones', 'slug' => $w['sub']->slug, 'category' => 'Gadgets', 'total_views' => 35], $sub[0]);
    }

    public function test_views_over_time_is_date_only_and_published_only(): void
    {
        $this->world();
        $old = Article::factory()->published()->create();
        $this->views($old, now()->subDays(40)->toDateString(), 99);   // outside the default 30 day window

        $this->actingAsUser($this->admin);
        $rows = $this->getJson('/api/analytics/views-over-time/')->assertOk()->json();
        $this->assertSame([
            ['date' => now()->subDay()->toDateString(), 'views' => 5],
            ['date' => now()->toDateString(), 'views' => 30],
        ], $rows);
        $this->getJson('/api/analytics/views-over-time/?days=1')->assertExactJson([['date' => now()->toDateString(), 'views' => 30]]);
        $this->assertCount(3, $this->getJson('/api/analytics/views-over-time/?days=90')->json());
        $this->getJson('/api/analytics/views-over-time/?days=zzz')->assertOk()->assertJsonCount(2);
    }

    public function test_publishing_activity(): void
    {
        $w = $this->world();
        Article::factory()->status(ArticleStatus::SCHEDULED)->create();
        Article::factory()->published()->create(['published_at' => now()->subDays(60)]);

        $res = $this->actingAsUser($this->admin)->getJson('/api/analytics/publishing/?days=30')->assertOk()->json();

        $this->assertSame(3, $res['published_count']);
        $this->assertSame(1, $res['scheduled_count']);
        $this->assertSame([['date' => now()->toDateString(), 'count' => 2]], $res['published_last_n_days']);
    }

    public function test_reporters_report(): void
    {
        $reporter = User::factory()->reporter()->create(['first_name' => 'Rita', 'last_name' => 'Rao']);
        Article::factory()->count(2)->create(['author_id' => $reporter->id]);                                   // DRAFT
        Article::factory()->status(ArticleStatus::SUBMITTED)->create(['author_id' => $reporter->id]);
        Article::factory()->published()->create(['author_id' => $reporter->id]);
        Article::factory()->status(ArticleStatus::REJECTED)->create(['author_id' => $reporter->id]);

        $rows = $this->actingAsUser($this->admin)->getJson('/api/analytics/reporters/')->assertOk()->json();

        $this->assertSame([
            'reporter_id' => $reporter->id, 'name' => 'Rita Rao', 'total_articles' => 5, 'submitted' => 3, 'published' => 1,
            'pending_review' => 1, 'rejected' => 1, 'changes_requested' => 0,
        ], $rows[0]);
    }

    public function test_article_daily_views_list_filters_search_ordering_and_pagination(): void
    {
        $w = $this->world();
        $this->actingAsUser($this->admin);

        $all = $this->getJson('/api/analytics/admin/article-daily-views/')->assertOk()->json();
        $this->assertSame(4, $all['count']);
        $this->assertNull($all['next']);
        $this->assertSame(['id', 'date', 'views', 'article_id', 'article_title', 'article_slug', 'article_status', 'category', 'industry', 'updated_at'], array_keys($all['results'][0]));
        $this->assertSame(now()->toDateString(), $all['results'][0]['date']);   // default -date
        $this->assertSame(now()->subDay()->toDateString(), $all['results'][3]['date']);
        $this->assertSame('Gadgets', $all['results'][0]['category']['name']);
        $this->assertSame('Tech', $all['results'][0]['industry']['name']);

        $this->getJson("/api/analytics/admin/article-daily-views/?article={$w['a1']->id}")->assertJsonPath('count', 2);
        $this->getJson('/api/analytics/admin/article-daily-views/?date_after='.now()->toDateString())->assertJsonPath('count', 3);
        $this->getJson('/api/analytics/admin/article-daily-views/?date_before='.now()->subDay()->toDateString())->assertJsonPath('count', 1);
        $this->getJson('/api/analytics/admin/article-daily-views/?search=phone')->assertJsonPath('count', 2);      // title or slug, case-insensitive
        $this->getJson('/api/analytics/admin/article-daily-views/?search=alpha+phone')->assertJsonPath('count', 2);
        $this->getJson('/api/analytics/admin/article-daily-views/?search=alpha+tablet')->assertJsonPath('count', 0);
        $this->getJson('/api/analytics/admin/article-daily-views/?search='.urlencode('%'))->assertJsonPath('count', 0);   // wildcard is escaped

        $byViews = $this->getJson('/api/analytics/admin/article-daily-views/?ordering=-views')->json('results');
        $this->assertSame([1000, 20, 10, 5], array_column($byViews, 'views'));
        $this->assertSame([5, 10, 20, 1000], array_column($this->getJson('/api/analytics/admin/article-daily-views/?ordering=views')->json('results'), 'views'));
        $this->assertSame(4, $this->getJson('/api/analytics/admin/article-daily-views/?ordering=bogus')->json('count'));

        $this->getJson('/api/analytics/admin/article-daily-views/?date_after=nope')->assertStatus(400)->assertJson(['date_after' => ['Enter a valid date.']]);
        $this->getJson('/api/analytics/admin/article-daily-views/?article=999999')->assertStatus(400)->assertJsonStructure(['article']);
        $this->getJson('/api/analytics/admin/article-daily-views/?page=2')->assertNotFound()->assertJson(['detail' => 'Invalid page.']);
    }

    public function test_daily_views_pagination_envelope(): void
    {
        $a = Article::factory()->published()->create();
        for ($i = 0; $i < 25; $i++) {
            $this->views($a, now()->subDays($i)->toDateString(), $i + 1);
        }
        $this->actingAsUser($this->admin);
        $p1 = $this->getJson('/api/analytics/admin/article-daily-views/')->json();
        $this->assertSame(25, $p1['count']);
        $this->assertCount(20, $p1['results']);
        $this->assertStringContainsString('page=2', $p1['next']);
        $this->assertNull($p1['previous']);
        $p2 = $this->getJson('/api/analytics/admin/article-daily-views/?page=2')->assertOk()->json();
        $this->assertCount(5, $p2['results']);
        $this->assertNull($p2['next']);
        $this->assertNotNull($p2['previous']);
    }
}
