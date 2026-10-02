<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\User;
use App\Support\DrfQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/**
 * PostgreSQL (and Django) sort NULL as the LARGEST value: last ascending, first descending. MySQL/MariaDB do
 * the opposite. The list APIs must keep the PostgreSQL placement (drafts have published_at = NULL).
 */
class MySqlNullOrderingTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    /** @return array{0: Article, 1: Article, 2: Article} draft (NULL published_at), old, new */
    private function trio(): array
    {
        $t = $this->tree();
        $mk = fn (string $title, $published, ArticleStatus $s) => Article::factory()->status($s)->create([
            'subcategory_id' => $t['subcategory']->id, 'title' => $title, 'published_at' => $published,
            'created_at' => now()->subHour(), // identical created_at: only published_at may decide the order
        ]);

        return [
            $mk('draft-null', null, ArticleStatus::DRAFT),
            $mk('old', now()->subDays(3), ArticleStatus::PUBLISHED),
            $mk('new', now()->subDay(), ArticleStatus::PUBLISHED),
        ];
    }

    private function titles(string $url, User $u): array
    {
        return array_column($this->as($u)->getJson($url)->assertOk()->json('results'), 'title');
    }

    public function test_admin_article_list_orders_null_published_at_like_postgres(): void
    {
        $this->trio();
        $admin = User::factory()->admin()->create();

        $this->assertSame(['old', 'new', 'draft-null'], $this->titles('/api/articles/?ordering=published_at', $admin), 'ASC: NULLS LAST');
        $this->assertSame(['draft-null', 'new', 'old'], $this->titles('/api/articles/?ordering=-published_at', $admin), 'DESC: NULLS FIRST');
    }

    public function test_reporter_workflow_lists_order_null_published_at_like_postgres(): void
    {
        [$draft] = $this->trio();
        $reporter = User::factory()->reporter()->create();
        Article::query()->update(['author_id' => $reporter->id, 'assigned_reporter_id' => $reporter->id]);

        foreach (['/api/articles/mine/', '/api/articles/assigned/'] as $base) {
            $this->assertSame(['old', 'new', 'draft-null'], $this->titles($base.'?ordering=published_at', $reporter), $base.' ASC');
            $this->assertSame(['draft-null', 'new', 'old'], $this->titles($base.'?ordering=-published_at', $reporter), $base.' DESC');
        }
        $this->assertNotNull($draft->id);
    }

    public function test_default_ordering_and_unknown_fields_are_unchanged(): void
    {
        $this->trio();
        $admin = User::factory()->admin()->create();
        // Same created_at everywhere: falls back to id DESC (the deterministic tiebreak), never NULL-dependent.
        $ids = array_column($this->as($admin)->getJson('/api/articles/')->json('results'), 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertSame($ids, array_column($this->as($admin)->getJson('/api/articles/?ordering=content')->json('results'), 'id'));
    }

    public function test_search_browse_and_popular_place_null_published_at_first_when_descending(): void
    {
        $t = $this->tree(subcategory: ['slug' => 'nulls']);
        // A PUBLISHED article without published_at (legacy/imported data): PostgreSQL DESC put it first.
        $nullPub = Article::factory()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'no-date', 'status' => ArticleStatus::PUBLISHED, 'published_at' => null]);
        $dated = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'dated']);

        $browse = array_column($this->as(null)->getJson('/api/search/?subcategory=nulls')->assertOk()->json('results'), 'title');
        $this->assertSame(['no-date', 'dated'], $browse);

        $admin = User::factory()->admin()->create();
        $popular = array_column($this->as($admin)->getJson('/api/analytics/articles/popular/')->assertOk()->json(), 'title');
        $this->assertSame(['no-date', 'dated'], $popular, 'equal (zero) views: published_at DESC tiebreak, NULL first');
        $this->assertNotNull($nullPub->id);
    }

    public function test_order_pg_helper_only_touches_nullable_columns_and_builds_valid_sql_on_both_engines(): void
    {
        $sql = fn ($q) => $q->toSql();
        $this->assertStringContainsString('`articles`.`published_at` IS NULL DESC, `articles`.`published_at` desc', $sql(DrfQuery::orderPg(Article::query(), 'articles.published_at', 'desc')));
        $this->assertStringContainsString('`published_at` IS NULL ASC, `published_at` asc', $sql(DrfQuery::orderPg(Article::query(), 'published_at', 'asc')));
        $this->assertStringNotContainsString('IS NULL', $sql(DrfQuery::orderPg(Article::query(), 'created_at', 'desc')));
        // Works on a plain query builder too and executes.
        DrfQuery::orderPg(DB::table('users'), 'last_login', 'desc')->get();
        $this->assertTrue(true);
    }

    public function test_users_last_login_null_placement_is_available_for_admin_lists(): void
    {
        $a = User::factory()->create(['last_login' => null]);
        $b = User::factory()->create(['last_login' => now()->subDay()]);
        $c = User::factory()->create(['last_login' => now()->subDays(2)]);
        $asc = DrfQuery::orderPg(User::query()->whereIn('id', [$a->id, $b->id, $c->id]), 'last_login', 'asc')->pluck('id')->all();
        $desc = DrfQuery::orderPg(User::query()->whereIn('id', [$a->id, $b->id, $c->id]), 'last_login', 'desc')->pluck('id')->all();
        $this->assertSame([$c->id, $b->id, $a->id], $asc);
        $this->assertSame([$a->id, $b->id, $c->id], $desc);
    }
}
