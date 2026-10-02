<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

class ArticlesRelatedTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    private function art(Subcategory $s, string $title, int $minutesAgo = 10, array $x = []): Article
    {
        return Article::factory()->published()->create(['subcategory_id' => $s->id, 'title' => $title, 'published_at' => now()->subMinutes($minutesAgo)] + $x);
    }

    public function test_ranking_tiers_subcategory_then_category_then_industry_then_shared_tags_then_recency(): void
    {
        $a = $this->tree();
        $sameCatSub = Subcategory::factory()->create(['category_id' => $a['category']->id]);
        $sameIndCat = Category::factory()->create(['industry_id' => $a['industry']->id]);
        $sameIndSub = Subcategory::factory()->create(['category_id' => $sameIndCat->id]);
        $other = $this->tree();
        $t1 = Tag::factory()->create();
        $t2 = Tag::factory()->create();

        $source = $this->art($a['subcategory'], 'source');
        $source->tags()->sync([$t1->id, $t2->id]);

        $p1old = $this->art($a['subcategory'], 'P1-old', 50);
        $p1new = $this->art($a['subcategory'], 'P1-new', 5);
        $p2 = $this->art($sameCatSub, 'P2', 1);
        $p3 = $this->art($sameIndSub, 'P3', 1);
        $p4two = $this->art($other['subcategory'], 'P4-two-tags', 100);
        $p4two->tags()->sync([$t1->id, $t2->id]);
        $p4one = $this->art($other['subcategory'], 'P4-one-tag', 1);
        $p4one->tags()->sync([$t1->id]);
        $this->art($other['subcategory'], 'unrelated');
        Article::factory()->create(['subcategory_id' => $a['subcategory']->id, 'title' => 'draft-same-sub']);
        Article::factory()->status(ArticleStatus::SUBMITTED)->create(['subcategory_id' => $a['subcategory']->id, 'title' => 'submitted-same-sub']);

        $r = $this->as(null)->getJson("/api/articles/{$source->slug}/related/?limit=20")->assertOk();
        $this->assertSame(['P1-new', 'P1-old', 'P2', 'P3', 'P4-two-tags', 'P4-one-tag'], array_column($r->json(), 'title'));
    }

    public function test_excludes_self_and_unpublished_and_is_a_plain_array_with_full_shape(): void
    {
        $t = $this->tree();
        $source = $this->art($t['subcategory'], 'source');
        $this->art($t['subcategory'], 'peer');
        Article::factory()->status(ArticleStatus::APPROVED)->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'approved']);
        $r = $this->as(null)->getJson("/api/articles/{$source->slug}/related/")->assertOk();
        $this->assertSame(['peer'], array_column($r->json(), 'title'));
        $this->assertArrayHasKey('subcategory', $r->json('0'));
        $this->assertTrue(array_is_list($r->json()));
        // admins and authors also only get PUBLISHED candidates
        $this->assertSame(['peer'], array_column($this->as(User::factory()->admin()->create())->getJson("/api/articles/{$source->slug}/related/")->json(), 'title'));
    }

    public function test_limit_default_cap_and_invalid_values(): void
    {
        $t = $this->tree();
        $source = $this->art($t['subcategory'], 'source');
        foreach (range(1, 22) as $i) {
            $this->art($t['subcategory'], "peer{$i}", $i);
        }
        $count = fn (string $q) => count($this->as(null)->getJson("/api/articles/{$source->slug}/related/{$q}")->assertOk()->json());
        $this->assertSame(4, $count(''));
        $this->assertSame(2, $count('?limit=2'));
        $this->assertSame(20, $count('?limit=999'));
        $this->assertSame(4, $count('?limit=abc'));
        $this->assertSame(4, $count('?limit=0'));
        $this->assertSame(4, $count('?limit=-3'));
    }

    public function test_source_visibility_follows_normal_rules(): void
    {
        $t = $this->tree();
        $author = User::factory()->reporter()->create();
        $draft = Article::factory()->create(['author_id' => $author->id, 'subcategory_id' => $t['subcategory']->id, 'title' => 'my draft']);
        $this->art($t['subcategory'], 'peer');
        $this->as(null)->getJson("/api/articles/{$draft->slug}/related/")->assertStatus(404);
        $this->as(User::factory()->reporter()->create())->getJson("/api/articles/{$draft->slug}/related/")->assertStatus(404);
        $this->as($author)->getJson("/api/articles/{$draft->slug}/related/")->assertOk()->assertJsonCount(1);
        $this->as(null)->getJson('/api/articles/ghost/related/')->assertStatus(404);
    }

    public function test_legacy_source_without_subcategory_matches_by_its_category_and_industry(): void
    {
        $t = $this->tree();
        $legacy = Article::factory()->published()->create(['subcategory_id' => null, 'category_id' => $t['category']->id, 'title' => 'legacy']);
        $this->art($t['subcategory'], 'same-cat-peer');
        $this->art($this->tree()['subcategory'], 'unrelated');
        $legacyPeer = Article::factory()->published()->create(['subcategory_id' => null, 'category_id' => $t['category']->id, 'title' => 'legacy-peer']);
        $titles = array_column($this->as(null)->getJson("/api/articles/{$legacy->slug}/related/")->json(), 'title');
        $this->assertEqualsCanonicalizing(['same-cat-peer', 'legacy-peer'], $titles);
        $this->assertNotNull($legacyPeer->id);
    }

    public function test_article_without_any_classification_or_tags_has_no_related(): void
    {
        $source = Article::factory()->published()->create(['subcategory_id' => null, 'category_id' => null]);
        $this->art($this->tree()['subcategory'], 'x');
        $this->as(null)->getJson("/api/articles/{$source->slug}/related/")->assertOk()->assertExactJson([]);
    }
}
