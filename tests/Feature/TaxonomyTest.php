<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Industry;
use App\Models\ReporterCategoryAssignment;
use App\Models\Subcategory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

class TaxonomyTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    // ---- Industries -------------------------------------------------------

    public function test_industry_list_is_a_public_plain_array_active_only_with_trailing_slash_optional(): void
    {
        Industry::factory()->create(['name' => 'Beta', 'slug' => 'beta', 'display_order' => 2]);
        Industry::factory()->create(['name' => 'Alpha', 'slug' => 'alpha', 'display_order' => 1]);
        Industry::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);

        foreach (['/api/industries/', '/api/industries'] as $url) {
            $r = $this->as(null)->getJson($url)->assertOk();
            $this->assertSame(['alpha', 'beta'], array_column($r->json(), 'slug'), 'display_order then name, inactive hidden');
        }
        $this->as(null)->getJson('/api/industries/')->assertJsonStructure([['id', 'name', 'slug', 'description', 'is_active', 'display_order', 'created_at', 'updated_at']]);
    }

    public function test_admin_sees_inactive_industries_but_plain_user_and_reporter_do_not(): void
    {
        Industry::factory()->create(['slug' => 'live']);
        Industry::factory()->create(['slug' => 'off', 'is_active' => false]);

        $this->as(User::factory()->admin()->create())->getJson('/api/industries/')->assertJsonCount(2);
        $this->as(User::factory()->create())->getJson('/api/industries/')->assertJsonCount(1);
        $this->as(User::factory()->reporter()->create())->getJson('/api/industries/')->assertJsonCount(1);
        $this->as(null)->getJson('/api/industries/off/')->assertStatus(404)->assertExactJson(['detail' => 'Not found.']);
        $this->as(User::factory()->admin()->create())->getJson('/api/industries/off/')->assertOk()->assertJsonPath('slug', 'off');
    }

    public function test_industry_write_permissions_by_role(): void
    {
        $payload = ['name' => 'Finance'];
        $this->as(null)->postJson('/api/industries/', $payload)->assertStatus(401)->assertJsonPath('detail', 'Authentication credentials were not provided.');
        foreach ([User::factory()->create(), User::factory()->subscriber()->create(), User::factory()->reporter()->create()] as $u) {
            $this->as($u)->postJson('/api/industries/', $payload)->assertStatus(403)->assertJsonPath('detail', 'Only administrators can modify this resource.');
        }
        $this->as(User::factory()->admin()->create())->postJson('/api/industries/', $payload)
            ->assertCreated()->assertJsonPath('slug', 'finance')->assertJsonPath('is_active', true)->assertJsonPath('display_order', 0);
    }

    public function test_industry_slug_is_unique_and_not_regenerated_on_rename_and_blank_slug_regenerates(): void
    {
        $admin = User::factory()->admin()->create();
        $this->as($admin)->postJson('/api/industries/', ['name' => 'Sports'])->assertCreated()->assertJsonPath('slug', 'sports');
        // A different name that slugifies identically gets a -2 suffix.
        $this->as($admin)->postJson('/api/industries/', ['name' => 'Sports!'])->assertCreated()->assertJsonPath('slug', 'sports-2');
        $this->as($admin)->patchJson('/api/industries/sports/', ['name' => 'Sporting'])->assertOk()->assertJsonPath('slug', 'sports');
        $this->as($admin)->patchJson('/api/industries/sports/', ['slug' => ''])->assertOk()->assertJsonPath('slug', 'sporting');
    }

    public function test_industry_validation_messages_match_django(): void
    {
        $admin = User::factory()->admin()->create();
        Industry::factory()->create(['name' => 'Business', 'slug' => 'business']);
        $this->as($admin)->postJson('/api/industries/', [])->assertStatus(400)->assertExactJson(['name' => ['This field is required.']]);
        $this->as($admin)->postJson('/api/industries/', ['name' => 'BUSINESS'])->assertStatus(400)->assertExactJson(['name' => ['An industry with this name already exists.']]);
        $this->as($admin)->postJson('/api/industries/', ['name' => 'X', 'slug' => 'Business'])->assertStatus(400)->assertExactJson(['slug' => ['An industry with this slug already exists.']]);
        $this->as($admin)->postJson('/api/industries/', ['name' => str_repeat('a', 101)])->assertStatus(400)->assertExactJson(['name' => ['Ensure this field has no more than 100 characters.']]);
        $this->as($admin)->postJson('/api/industries/', ['name' => 'Y', 'slug' => 'bad slug!'])->assertStatus(400)->assertJsonPath('slug.0', 'Enter a valid "slug" consisting of letters, numbers, underscores or hyphens.');
    }

    public function test_industry_search_and_ordering(): void
    {
        Industry::factory()->create(['name' => 'Technology', 'slug' => 't', 'description' => 'gadgets and ai', 'display_order' => 3]);
        Industry::factory()->create(['name' => 'Sports', 'slug' => 's', 'description' => 'cricket', 'display_order' => 1]);
        $this->as(null)->getJson('/api/industries/?search=CRICK')->assertJsonCount(1)->assertJsonPath('0.slug', 's');
        $this->as(null)->getJson('/api/industries/?search=gadgets ai')->assertJsonCount(1)->assertJsonPath('0.slug', 't');
        $this->as(null)->getJson('/api/industries/?search=gadgets cricket')->assertJsonCount(0);
        $this->as(null)->getJson('/api/industries/?ordering=-name')->assertJsonPath('0.slug', 't');
        $this->as(null)->getJson('/api/industries/?ordering=bogus,-display_order')->assertJsonPath('0.slug', 't');
    }

    public function test_activate_and_deactivate_are_admin_only_post_and_return_the_row(): void
    {
        $i = Industry::factory()->create(['slug' => 'ind']);
        $this->as(User::factory()->reporter()->create())->postJson('/api/industries/ind/deactivate/')->assertStatus(403);
        $admin = User::factory()->admin()->create();
        $this->as($admin)->postJson('/api/industries/ind/deactivate/')->assertOk()->assertJsonPath('is_active', false);
        $this->assertFalse($i->fresh()->is_active);
        $this->as($admin)->postJson('/api/industries/ind/activate/')->assertOk()->assertJsonPath('is_active', true);
    }

    // ---- Categories -------------------------------------------------------

    public function test_category_create_via_industry_slug_and_nested_read_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $ind = Industry::factory()->create(['slug' => 'business']);
        $r = $this->as($admin)->postJson('/api/categories/', ['name' => 'Startups', 'industry_slug' => 'business'])->assertCreated();
        $r->assertJsonPath('slug', 'startups')->assertJsonPath('industry.slug', 'business')->assertJsonPath('industry.id', $ind->id);
        $r->assertJsonStructure(['id', 'name', 'slug', 'description', 'image_url', 'image_storage_path', 'industry' => ['id', 'name', 'slug'], 'is_active', 'created_at', 'updated_at']);
        $this->assertArrayNotHasKey('industry_slug', $r->json());
    }

    public function test_category_requires_active_existing_industry_slug(): void
    {
        $admin = User::factory()->admin()->create();
        Industry::factory()->create(['slug' => 'off', 'is_active' => false]);
        $this->as($admin)->postJson('/api/categories/', ['name' => 'A'])->assertStatus(400)->assertExactJson(['industry_slug' => ['This field is required.']]);
        $this->as($admin)->postJson('/api/categories/', ['name' => 'A', 'industry_slug' => 'nope'])->assertStatus(400)->assertExactJson(['industry_slug' => ['Object with slug=nope does not exist.']]);
        $this->as($admin)->postJson('/api/categories/', ['name' => 'A', 'industry_slug' => 'off'])->assertStatus(400)->assertJsonPath('industry_slug.0', 'Object with slug=off does not exist.');
    }

    public function test_category_filter_by_industry_and_patch_may_omit_industry_slug(): void
    {
        $admin = User::factory()->admin()->create();
        $a = Industry::factory()->create(['slug' => 'a']);
        $b = Industry::factory()->create(['slug' => 'b']);
        Category::factory()->create(['industry_id' => $a->id, 'slug' => 'ca', 'name' => 'CA']);
        Category::factory()->create(['industry_id' => $b->id, 'slug' => 'cb', 'name' => 'CB']);
        $this->as(null)->getJson('/api/categories/?industry=a')->assertJsonCount(1)->assertJsonPath('0.slug', 'ca');
        $this->as($admin)->patchJson('/api/categories/ca/', ['description' => 'x'])->assertOk()->assertJsonPath('description', 'x')->assertJsonPath('industry.slug', 'a');
        // PUT is a full update: industry_slug is required again.
        $this->as($admin)->putJson('/api/categories/ca/', ['name' => 'CA'])->assertStatus(400)->assertJsonPath('industry_slug.0', 'This field is required.');
        // Moving a category to another industry.
        $this->as($admin)->patchJson('/api/categories/ca/', ['industry_slug' => 'b'])->assertOk()->assertJsonPath('industry.slug', 'b');
    }

    public function test_category_image_url_validation_and_public_hides_inactive(): void
    {
        $admin = User::factory()->admin()->create();
        $i = Industry::factory()->create(['slug' => 'i']);
        $this->as($admin)->postJson('/api/categories/', ['name' => 'C', 'industry_slug' => 'i', 'image_url' => 'not a url'])->assertStatus(400)->assertJsonPath('image_url.0', 'Enter a valid URL.');
        $this->as($admin)->postJson('/api/categories/', ['name' => 'C', 'industry_slug' => 'i', 'image_url' => 'https://cdn.example.com/x.jpg'])->assertCreated()->assertJsonPath('image_url', 'https://cdn.example.com/x.jpg');
        Category::factory()->create(['industry_id' => $i->id, 'slug' => 'hid', 'is_active' => false]);
        $this->as(null)->getJson('/api/categories/')->assertJsonCount(1);
        $this->as(null)->getJson('/api/categories/hid/')->assertStatus(404);
        $this->as($admin)->getJson('/api/categories/')->assertJsonCount(2);
    }

    // ---- Subcategories ----------------------------------------------------

    public function test_subcategory_create_via_category_slug_with_nested_category_and_industry(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree(category: ['slug' => 'cricket', 'name' => 'Cricket']);
        $r = $this->as($admin)->postJson('/api/subcategories/', ['name' => 'World Cup', 'category_slug' => 'cricket', 'display_order' => 3])->assertCreated();
        $r->assertJsonPath('slug', 'world-cup')->assertJsonPath('category.slug', 'cricket')->assertJsonPath('category.industry.id', $t['industry']->id)->assertJsonPath('display_order', 3);
        $this->assertArrayNotHasKey('category_slug', $r->json());
    }

    public function test_subcategory_detail_uses_numeric_id_and_slug_is_unique_only_within_a_category(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->tree(category: ['slug' => 'cat-a'])['category'];
        $b = $this->tree(category: ['slug' => 'cat-b'])['category'];
        $r1 = $this->as($admin)->postJson('/api/subcategories/', ['name' => 'News', 'category_slug' => 'cat-a'])->assertCreated()->assertJsonPath('slug', 'news');
        $r2 = $this->as($admin)->postJson('/api/subcategories/', ['name' => 'News', 'category_slug' => 'cat-b'])->assertCreated()->assertJsonPath('slug', 'news');
        $r3 = $this->as($admin)->postJson('/api/subcategories/', ['name' => 'News', 'category_slug' => 'cat-a'])->assertCreated()->assertJsonPath('slug', 'news-2');
        $this->as(null)->getJson('/api/subcategories/'.$r2->json('id').'/')->assertOk()->assertJsonPath('category.slug', 'cat-b');
        $this->as(null)->getJson('/api/subcategories/news/')->assertStatus(404);
        $this->as($admin)->postJson('/api/subcategories/', ['name' => 'Other', 'slug' => 'NEWS', 'category_slug' => 'cat-a'])
            ->assertStatus(400)->assertExactJson(['slug' => ['A subcategory with this slug already exists under this category.']]);
        $this->assertNotNull($r3->json('id'));
        $this->assertNotNull($r1->json('id'));
    }

    public function test_subcategory_filters_and_public_visibility_depends_on_parent_category(): void
    {
        $t1 = $this->tree(industry: ['slug' => 'ind1'], category: ['slug' => 'c1'], subcategory: ['slug' => 'shared', 'name' => 'S1']);
        $t2 = $this->tree(industry: ['slug' => 'ind2'], category: ['slug' => 'c2'], subcategory: ['slug' => 'shared', 'name' => 'S2']);
        $off = Subcategory::factory()->create(['category_id' => $t1['category']->id, 'is_active' => false, 'slug' => 'off']);
        $t3 = $this->tree(category: ['slug' => 'c3', 'is_active' => false], subcategory: ['slug' => 'orphan']);

        $this->as(null)->getJson('/api/subcategories/')->assertJsonCount(2);
        $this->as(null)->getJson('/api/subcategories/?category=c1')->assertJsonCount(1)->assertJsonPath('0.slug', 'shared');
        $this->as(null)->getJson('/api/subcategories/?industry=ind2')->assertJsonCount(1)->assertJsonPath('0.name', 'S2');
        $this->as(null)->getJson('/api/subcategories/?slug=shared')->assertJsonCount(2);
        $this->as(null)->getJson('/api/subcategories/?slug=shared&category=c2')->assertJsonCount(1)->assertJsonPath('0.name', 'S2');
        $this->as(User::factory()->admin()->create())->getJson('/api/subcategories/')->assertJsonCount(4);
        $this->as(null)->getJson('/api/subcategories/'.$off->id.'/')->assertStatus(404);
        $this->as(null)->getJson('/api/subcategories/'.$t3['subcategory']->id.'/')->assertStatus(404);
    }

    public function test_subcategory_requires_an_active_category(): void
    {
        $admin = User::factory()->admin()->create();
        $this->tree(category: ['slug' => 'off', 'is_active' => false]);
        $this->as($admin)->postJson('/api/subcategories/', ['name' => 'X', 'category_slug' => 'off'])->assertStatus(400)->assertJsonPath('category_slug.0', 'Object with slug=off does not exist.');
        $this->as($admin)->postJson('/api/subcategories/', ['name' => 'X'])->assertStatus(400)->assertJsonPath('category_slug.0', 'This field is required.');
    }

    public function test_moving_a_subcategory_to_a_category_where_its_slug_exists_is_a_400_not_a_500(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->tree(category: ['slug' => 'ma'], subcategory: ['slug' => 'dup'])['subcategory'];
        $this->tree(category: ['slug' => 'mb'], subcategory: ['slug' => 'dup']);
        $this->as($admin)->patchJson('/api/subcategories/'.$a->id.'/', ['category_slug' => 'mb'])->assertStatus(400)->assertJsonPath('slug.0', 'A subcategory with this slug already exists under this category.');
    }

    // ---- Tags -------------------------------------------------------------

    public function test_tag_permissions_reporter_may_only_create(): void
    {
        $reporter = User::factory()->reporter()->create();
        $admin = User::factory()->admin()->create();
        $this->as($reporter)->postJson('/api/tags/', ['name' => 'budget-2026'])->assertCreated()->assertJsonPath('slug', 'budget-2026');
        $this->as($reporter)->patchJson('/api/tags/budget-2026/', ['name' => 'x'])->assertStatus(403)->assertJsonPath('detail', 'Only administrators can modify or remove tags.');
        $this->as($reporter)->deleteJson('/api/tags/budget-2026/')->assertStatus(403);
        $this->as(User::factory()->create())->postJson('/api/tags/', ['name' => 'nope'])->assertStatus(403);
        $this->as(User::factory()->subscriber()->create())->postJson('/api/tags/', ['name' => 'nope'])->assertStatus(403);
        $this->as(null)->postJson('/api/tags/', ['name' => 'nope'])->assertStatus(401);
        $this->as($admin)->patchJson('/api/tags/budget-2026/', ['name' => 'Budget 2026!'])->assertOk()->assertJsonPath('slug', 'budget-2026');
    }

    public function test_tag_validation_and_list_search(): void
    {
        $admin = User::factory()->admin()->create();
        Tag::factory()->create(['name' => 'IPO', 'slug' => 'ipo']);
        Tag::factory()->create(['name' => 'Budget', 'slug' => 'budget']);
        $this->as($admin)->postJson('/api/tags/', ['name' => 'ipo'])->assertStatus(400)->assertExactJson(['name' => ['A tag with this name already exists.']]);
        $this->as($admin)->postJson('/api/tags/', ['name' => str_repeat('t', 61)])->assertStatus(400)->assertJsonPath('name.0', 'Ensure this field has no more than 60 characters.');
        $this->as(null)->getJson('/api/tags/?search=bud')->assertJsonCount(1)->assertJsonPath('0.slug', 'budget');
        $this->as(null)->getJson('/api/tags/')->assertJsonPath('0.slug', 'budget')->assertJsonPath('1.slug', 'ipo');
        $this->as(null)->getJson('/api/tags/ipo/')->assertOk()->assertJsonStructure(['id', 'name', 'slug', 'created_at']);
        $this->as(null)->getJson('/api/tags/missing/')->assertStatus(404);
    }

    public function test_tag_delete_is_a_real_delete_that_detaches_from_articles(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = Tag::factory()->create(['slug' => 'gone']);
        $article = Article::factory()->create();
        $article->tags()->sync([$tag->id]);
        $this->as($admin)->deleteJson('/api/tags/gone/')->assertNoContent();
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertSame(0, $article->tags()->count());
    }

    // ---- Protection -------------------------------------------------------

    public function test_industry_with_categories_cannot_be_deleted(): void
    {
        $t = $this->tree();
        $r = $this->as(User::factory()->admin()->create())->deleteJson('/api/industries/'.$t['industry']->slug.'/')->assertStatus(409);
        $this->assertStringContainsString('1 category', $r->json('detail'));
        $this->assertTrue($t['industry']->fresh()->is_active);
        $this->assertDatabaseHas('industries', ['id' => $t['industry']->id]);
    }

    public function test_category_with_subcategories_articles_or_assignments_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $this->as($admin)->deleteJson('/api/categories/'.$t['category']->slug.'/')->assertStatus(409)->assertJsonStructure(['detail']);

        $onlyAssignments = Category::factory()->create(['industry_id' => $t['industry']->id]);
        ReporterCategoryAssignment::query()->create(['reporter_id' => User::factory()->reporter()->create()->id, 'category_id' => $onlyAssignments->id]);
        $this->as($admin)->deleteJson('/api/categories/'.$onlyAssignments->slug.'/')->assertStatus(409);

        // legacy article that only has the category (no subcategory) still blocks it
        $legacy = Category::factory()->create(['industry_id' => $t['industry']->id]);
        Article::factory()->create(['subcategory_id' => null, 'category_id' => $legacy->id]);
        $this->as($admin)->deleteJson('/api/categories/'.$legacy->slug.'/')->assertStatus(409);
        $this->assertTrue($legacy->fresh()->is_active);
    }

    public function test_subcategory_with_articles_cannot_be_deleted(): void
    {
        $t = $this->tree();
        Article::factory()->create(['subcategory_id' => $t['subcategory']->id]);
        $this->as(User::factory()->admin()->create())->deleteJson('/api/subcategories/'.$t['subcategory']->id.'/')->assertStatus(409);
        $this->assertTrue($t['subcategory']->fresh()->is_active);
    }

    public function test_unreferenced_taxonomy_delete_soft_deactivates_like_django(): void
    {
        $admin = User::factory()->admin()->create();
        $i = Industry::factory()->create();
        $c = Category::factory()->create(['industry_id' => Industry::factory()]);
        $s = Subcategory::factory()->create(['category_id' => Category::factory()]);
        $this->as($admin)->deleteJson('/api/industries/'.$i->slug.'/')->assertNoContent();
        $this->as($admin)->deleteJson('/api/categories/'.$c->slug.'/')->assertNoContent();
        $this->as($admin)->deleteJson('/api/subcategories/'.$s->id.'/')->assertNoContent();
        $this->assertFalse($i->fresh()->is_active);
        $this->assertFalse($c->fresh()->is_active);
        $this->assertFalse($s->fresh()->is_active);
        $this->as(User::factory()->reporter()->create())->deleteJson('/api/industries/'.$i->slug.'/')->assertStatus(403);
    }

    public function test_database_restricts_hard_deleting_referenced_taxonomy(): void
    {
        $t = $this->tree();
        $this->expectException(QueryException::class);
        $t['industry']->delete();
    }
}
