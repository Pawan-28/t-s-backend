<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\User;
use Database\Factories\AdvertisementFactory;
use Database\Factories\ArticleFactory;
use Database\Factories\ArticleImageFactory;
use Database\Factories\CategoryFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MediaTestHelpers;
use Tests\TestCase;

class MediaArticleImagesTest extends TestCase
{
    use MediaTestHelpers;
    use RefreshDatabase;

    private User $author;

    private User $admin;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureBunny();
        $this->author = UserFactory::new()->reporter()->create();
        $this->admin = UserFactory::new()->admin()->create();
        $this->article = ArticleFactory::new()->create(['author_id' => $this->author->id]);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsUser($u);
    }

    /** Public GETs only personalise when a bearer header is present (ApiUser::resolve); actingAs() alone sends none. */
    private function get_(string $url)
    {
        return $this->getJson($url, ['Authorization' => 'Bearer test-token']);
    }

    private function url(?Article $a = null, string $suffix = ''): string
    {
        return '/api/articles/'.($a ?? $this->article)->slug.'/images/'.$suffix;
    }

    private function postImage(?string $bytes = null, array $fields = [], ?Article $a = null, string $name = 'photo.jpg')
    {
        return $this->post($this->url($a), ['image' => $this->upload($bytes ?? $this->imageBytes(), $name)] + $fields, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------- upload

    public function test_upload_stores_object_row_metadata_and_returns_django_shape(): void
    {
        $this->fakeBunny();
        $bytes = $this->imageBytes(640, 480);
        $res = $this->as($this->author)->postImage($bytes, ['alt_text' => 'A sunrise', 'caption' => 'Dawn', 'display_order' => '3']);

        $res->assertCreated()->assertJsonStructure([
            'id', 'bunny_url', 'alt_text', 'caption', 'is_featured', 'display_order',
            'uploaded_by' => ['id', 'email'],
            'metadata' => ['original_filename', 'content_type', 'file_size_bytes', 'width', 'height', 'checksum'],
            'created_at', 'updated_at',
        ]);
        $res->assertJsonMissingPath('image')->assertJsonMissingPath('bunny_storage_path');
        $json = $res->json();
        $this->assertSame('A sunrise', $json['alt_text']);
        $this->assertSame(3, $json['display_order']);
        $this->assertSame(['id' => $this->author->id, 'email' => $this->author->email], $json['uploaded_by']);
        $this->assertSame('photo.jpg', $json['metadata']['original_filename']);
        $this->assertSame(['image/jpeg', 640, 480], [$json['metadata']['content_type'], $json['metadata']['width'], $json['metadata']['height']]);

        $puts = $this->bunnyRequests('PUT');
        $this->assertCount(1, $puts);
        $row = ArticleImage::query()->findOrFail($json['id']);
        $this->assertMatchesRegularExpression('#^articles/'.preg_quote($this->article->slug, '#').'/[0-9a-f]{32}\.jpg$#', $row->bunny_storage_path);
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$row->bunny_storage_path, $puts[0]->url());
        $this->assertSame('https://test-cdn.b-cdn.net/'.$row->bunny_storage_path, $json['bunny_url']);
        // stored facts describe the object that was actually uploaded
        $this->assertSame(hash('sha256', $puts[0]->body()), $json['metadata']['checksum']);
        $this->assertSame(strlen($puts[0]->body()), $json['metadata']['file_size_bytes']);
        $this->assertSame($this->article->id, $row->article_id);
        $this->assertSame($this->author->id, $row->uploaded_by_id);
    }

    public function test_first_image_is_featured_automatically_without_any_param_and_shows_in_article_resource(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->author)->postImage();
        $res->assertCreated()->assertJsonPath('is_featured', true);

        $article = Article::query()->with('featuredImage')->findOrFail($this->article->id);
        $this->assertSame($res->json('bunny_url'), $article->featuredImage?->bunny_url);
    }

    public function test_later_images_are_not_featured_unless_requested_and_exactly_one_stays_featured(): void
    {
        $this->fakeBunny();
        $first = $this->as($this->author)->postImage()->json('id');
        $second = $this->postImage()->assertCreated()->json();
        $this->assertFalse($second['is_featured']);

        $third = $this->postImage(fields: ['is_featured' => 'true'])->assertCreated()->json();
        $this->assertTrue($third['is_featured']);
        $featured = ArticleImage::query()->where('article_id', $this->article->id)->where('is_featured', true)->pluck('id')->all();
        $this->assertSame([$third['id']], $featured);
        $this->assertFalse(ArticleImage::query()->find($first)->is_featured);
    }

    public function test_oversized_dimensions_are_resized_before_upload_keeping_aspect_ratio(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->author)->postImage($this->imageBytes(4000, 3000));

        $res->assertCreated()->assertJsonPath('metadata.width', 2000)->assertJsonPath('metadata.height', 1500);
        $sent = $this->decodeBody($this->bunnyRequests('PUT')[0]);
        $this->assertSame([2000, 1500], [$sent['w'], $sent['h']]);
        $this->assertLessThanOrEqual(2000, max($sent['w'], $sent['h']));
    }

    public function test_exif_rotated_photo_is_stored_upright(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->author)->postImage($this->imageBytes(300, 200, 'jpeg', false, 6));
        $res->assertCreated()->assertJsonPath('metadata.width', 200)->assertJsonPath('metadata.height', 300);
    }

    public function test_alpha_png_stays_png_object(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->author)->postImage($this->imageBytes(200, 200, 'png', true), name: 'logo.png');
        $res->assertCreated()->assertJsonPath('metadata.content_type', 'image/png');
        $this->assertStringEndsWith('.png', $res->json('bunny_url'));
    }

    public function test_renamed_non_image_is_rejected_and_nothing_is_stored(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->author)->postImage("<?php echo 'pwned';", name: 'evil.jpg');
        $res->assertStatus(400)->assertJsonPath('image.0', 'Upload a valid image. The file you uploaded was either not an image or a corrupted image.');
        $this->assertSame(0, ArticleImage::query()->count());
        Http::assertNothingSent();
    }

    public function test_svg_and_html_masquerading_as_png_are_rejected(): void
    {
        $this->fakeBunny();
        $this->as($this->author)->postImage('<svg xmlns="http://www.w3.org/2000/svg"/>', name: 'a.png')->assertStatus(400);
        $this->postImage('<html><script>alert(1)</script></html>', name: 'b.jpg')->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_file_larger_than_configured_limit_is_rejected(): void
    {
        $this->fakeBunny();
        config(['portal.images.max_upload_mb' => 1]);
        $big = $this->imageBytes(300, 300).str_repeat('A', 1024 * 1024 + 10);
        $this->as($this->author)->postImage($big)
            ->assertStatus(400)->assertJsonPath('image.0', 'Image exceeds the maximum upload size of 1MB.');
        $this->assertSame(0, ArticleImage::query()->count());
        Http::assertNothingSent();
    }

    public function test_missing_image_field_and_bad_metadata_values_give_drf_style_400(): void
    {
        $this->fakeBunny();
        $this->as($this->author)->post($this->url(), [], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertExactJson(['image' => ['No file was submitted.']]);

        $this->postImage(fields: ['is_featured' => 'maybe', 'display_order' => '-1', 'alt_text' => str_repeat('x', 256)])
            ->assertStatus(400)
            ->assertJsonPath('is_featured.0', '"maybe" is not a valid boolean.')
            ->assertJsonPath('display_order.0', 'Ensure this value is greater than or equal to 0.')
            ->assertJsonPath('alt_text.0', 'Ensure this field has no more than 255 characters.');
    }

    public function test_bunny_failure_gives_clean_502_without_db_row_or_secret(): void
    {
        $this->fakeBunny(put: 500);
        $res = $this->as($this->author)->postImage();
        $res->assertStatus(502)->assertJsonPath('detail', 'Image storage is temporarily unavailable. Please try again.');
        $this->assertSame(0, ArticleImage::query()->count());
        $this->assertStringNotContainsString(self::BUNNY_KEY, $res->getContent());
        $this->assertCount(0, $this->bunnyRequests('DELETE'));
    }

    public function test_db_failure_after_upload_removes_the_fresh_object_again(): void
    {
        $this->fakeBunny();
        ArticleImage::creating(fn () => throw new \RuntimeException('db down'));
        $this->as($this->author)->postImage()->assertStatus(500);

        $put = $this->bunnyRequests('PUT');
        $del = $this->bunnyRequests('DELETE');
        $this->assertCount(1, $put);
        $this->assertCount(1, $del);
        $this->assertSame($put[0]->url(), $del[0]->url());
        $this->assertSame(0, ArticleImage::query()->count());
    }

    // ------------------------------------------------------------- list

    public function test_list_is_a_plain_array_ordered_by_display_order_then_created(): void
    {
        $b = ArticleImageFactory::new()->forArticle($this->article)->create(['display_order' => 2]);
        $a = ArticleImageFactory::new()->forArticle($this->article)->create(['display_order' => 1]);
        $other = ArticleFactory::new()->published()->create();
        ArticleImageFactory::new()->forArticle($other)->create();

        $res = $this->as($this->author)->get_($this->url());
        $res->assertOk();
        $this->assertTrue(array_is_list($res->json()));
        $this->assertSame([$a->id, $b->id], array_column($res->json(), 'id'));
        $this->assertArrayNotHasKey('results', $res->json());
        $this->assertArrayNotHasKey('count', $res->json());
    }

    public function test_list_visibility(): void
    {
        ArticleImageFactory::new()->forArticle($this->article)->create();
        $published = ArticleFactory::new()->published()->create();
        ArticleImageFactory::new()->forArticle($published)->create();
        $assigned = UserFactory::new()->reporter()->create();
        $this->article->update(['assigned_reporter_id' => $assigned->id]);
        $stranger = UserFactory::new()->reporter()->create();

        // anonymous may read a PUBLISHED article's images, never a draft's
        $this->getJson($this->url($published))->assertOk()->assertJsonCount(1);
        // (404, not 403: an unpublished slug must be indistinguishable from a missing one)
        $this->getJson($this->url())->assertStatus(404)->assertJsonPath('detail', 'Not found.');
        $this->as($stranger)->get_($this->url())->assertStatus(404);
        $this->as($this->author)->get_($this->url())->assertOk()->assertJsonCount(1);
        $this->as($assigned)->get_($this->url())->assertOk();
        $this->as($this->admin)->get_($this->url())->assertOk();
        $this->getJson('/api/articles/nope-nope/images/')->assertStatus(404)->assertJsonPath('detail', 'Not found.');
    }

    // ------------------------------------------------------------- permissions

    public function test_upload_permission_matrix(): void
    {
        $this->fakeBunny();
        $subscriber = UserFactory::new()->create();
        $stranger = UserFactory::new()->reporter()->create();
        $assigned = UserFactory::new()->reporter()->create();
        $published = ArticleFactory::new()->published()->create(['author_id' => $this->author->id]);
        $underReview = ArticleFactory::new()->status(ArticleStatus::UNDER_REVIEW)->create(['author_id' => $this->author->id, 'assigned_reporter_id' => $assigned->id]);
        $submitted = ArticleFactory::new()->status(ArticleStatus::SUBMITTED)->create(['author_id' => $this->author->id]);

        // anonymous
        $this->postImage()->assertStatus(401);
        // plain user (subscriber)
        $this->as($subscriber)->postImage()->assertStatus(403)
            ->assertJsonPath('detail', 'Only reporters and administrators can upload or modify article images.');
        // reporter who does not own the draft
        $this->as($stranger)->postImage()->assertStatus(404);
        // ... nor a published one
        $this->postImage(a: $published)->assertStatus(403)
            ->assertJsonPath('detail', 'You can only upload images to your own articles, or one assigned to you for review.');
        // author on own draft ok; not once the article left the editable statuses
        $this->as($this->author)->postImage()->assertCreated();
        $this->postImage(a: $submitted)->assertStatus(403);
        $this->postImage(a: $published)->assertStatus(403);
        // assigned reporter while UNDER_REVIEW
        $this->as($assigned)->postImage(a: $underReview)->assertCreated();
        // admin always
        $this->as($this->admin)->postImage(a: $published)->assertCreated();

        $this->assertSame(3, ArticleImage::query()->count());
        $this->assertCount(3, $this->bunnyRequests('PUT'));
    }

    // ------------------------------------------------------------- update (PATCH)

    public function test_patch_updates_metadata_only_and_ignores_file_field(): void
    {
        $this->fakeBunny();
        $img = ArticleImageFactory::new()->forArticle($this->article)->featured()->create();

        $res = $this->as($this->author)->patchJson($this->url(suffix: $img->id.'/'), ['alt_text' => 'new alt', 'caption' => 'cap', 'display_order' => 7]);
        $res->assertOk()->assertJsonPath('alt_text', 'new alt')->assertJsonPath('caption', 'cap')->assertJsonPath('display_order', 7);
        $this->assertSame($img->bunny_url, $res->json('bunny_url'));

        $this->call('PATCH', $this->url(suffix: $img->id.'/'), ['alt_text' => 'multipart alt'], [], ['image' => $this->upload($this->imageBytes())], ['HTTP_ACCEPT' => 'application/json'])
            ->assertOk()->assertJsonPath('alt_text', 'multipart alt');
        $this->assertSame($img->bunny_storage_path, $img->fresh()->bunny_storage_path, 'PATCH never replaces the file (Django)');
        Http::assertNothingSent();
    }

    public function test_patch_is_featured_true_demotes_siblings_and_false_never_leaves_zero(): void
    {
        $a = ArticleImageFactory::new()->forArticle($this->article)->featured()->create();
        $b = ArticleImageFactory::new()->forArticle($this->article)->create();

        $this->as($this->author)->patchJson($this->url(suffix: $b->id.'/'), ['is_featured' => true])->assertOk()->assertJsonPath('is_featured', true);
        $this->assertFalse($a->fresh()->is_featured);
        $this->assertTrue($b->fresh()->is_featured);

        // trying to un-feature the only featured image keeps the invariant
        $this->patchJson($this->url(suffix: $b->id.'/'), ['is_featured' => false])->assertOk()->assertJsonPath('is_featured', true);
        $this->assertSame(1, ArticleImage::query()->where('article_id', $this->article->id)->where('is_featured', true)->count());
    }

    public function test_patch_validation_and_permissions(): void
    {
        $img = ArticleImageFactory::new()->forArticle($this->article)->create();
        $stranger = UserFactory::new()->reporter()->create();
        $url = $this->url(suffix: $img->id.'/');

        $this->patchJson($url, ['alt_text' => 'x'])->assertStatus(401);
        $this->as(UserFactory::new()->create())->patchJson($url, ['alt_text' => 'x'])->assertStatus(403);
        $this->as($stranger)->patchJson($url, ['alt_text' => 'x'])->assertStatus(404)
            ->assertJsonPath('detail', 'Not found.');
        $this->as($this->author)->patchJson($url, ['display_order' => 'abc'])->assertStatus(400)->assertJsonPath('display_order.0', 'A valid integer is required.');
        $this->patchJson($this->url(suffix: '999999/'), ['alt_text' => 'x'])->assertStatus(404);
        $this->assertSame('', $img->fresh()->alt_text);
    }

    public function test_image_of_another_article_is_not_reachable_through_this_article(): void
    {
        $mine = ArticleFactory::new()->create(['author_id' => $this->author->id]);
        $foreign = ArticleImageFactory::new()->forArticle($mine)->create();
        $this->fakeBunny();

        $this->as($this->author)->patchJson($this->url(suffix: $foreign->id.'/'), ['alt_text' => 'hijack'])->assertStatus(404);
        $this->deleteJson($this->url(suffix: $foreign->id.'/'))->assertStatus(404);
        $this->assertNotNull(ArticleImage::query()->find($foreign->id));
        $this->assertCount(0, $this->bunnyRequests('DELETE'));
    }

    public function test_retrieve_single_image_requires_privileged_user(): void
    {
        $img = ArticleImageFactory::new()->forArticle($this->article)->create();
        $this->getJson($this->url(suffix: $img->id.'/'))->assertStatus(401);
        $this->as($this->author)->get_($this->url(suffix: $img->id.'/'))->assertOk()->assertJsonPath('id', $img->id);
    }

    // ------------------------------------------------------------- replace

    public function test_replace_uploads_new_object_then_deletes_only_the_old_one(): void
    {
        $this->fakeBunny();
        $other = ArticleImageFactory::new()->forArticle($this->article)->create();
        $img = ArticleImageFactory::new()->forArticle($this->article)->featured()->create(['alt_text' => 'keep me', 'display_order' => 4]);
        $oldPath = $img->bunny_storage_path;

        $res = $this->as($this->author)->post($this->url(suffix: $img->id.'/replace/'), ['image' => $this->upload($this->imageBytes(3000, 1000))], ['Accept' => 'application/json']);
        $res->assertOk()->assertJsonPath('id', $img->id)->assertJsonPath('is_featured', true)
            ->assertJsonPath('alt_text', 'keep me')->assertJsonPath('metadata.width', 2000)->assertJsonPath('metadata.height', 667);

        $row = $img->fresh();
        $this->assertNotSame($oldPath, $row->bunny_storage_path);
        $this->assertStringStartsWith('articles/'.$this->article->slug.'/', $row->bunny_storage_path);
        $this->assertSame('https://test-cdn.b-cdn.net/'.$row->bunny_storage_path, $row->bunny_url);

        $put = $this->bunnyRequests('PUT');
        $del = $this->bunnyRequests('DELETE');
        $this->assertCount(1, $put);
        $this->assertCount(1, $del);
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$row->bunny_storage_path, $put[0]->url());
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$oldPath, $del[0]->url(), 'only the replaced row\'s OLD object is deleted');
        // upload happened before delete
        $order = collect(Http::recorded())->map(fn ($p) => $p[0]->method())->all();
        $this->assertSame(['PUT', 'DELETE'], $order);
        // sibling untouched
        $this->assertSame($other->bunny_storage_path, $other->fresh()->bunny_storage_path);
    }

    public function test_replace_failure_at_bunny_leaves_row_and_old_object_alone(): void
    {
        $this->fakeBunny(put: 500);
        $img = ArticleImageFactory::new()->forArticle($this->article)->featured()->create();
        $before = $img->bunny_storage_path;

        $this->as($this->author)->post($this->url(suffix: $img->id.'/replace'), ['image' => $this->upload($this->imageBytes())], ['Accept' => 'application/json'])
            ->assertStatus(502);
        $this->assertSame($before, $img->fresh()->bunny_storage_path);
        $this->assertCount(0, $this->bunnyRequests('DELETE'));
    }

    public function test_replace_never_deletes_a_path_outside_the_articles_own_prefix(): void
    {
        $this->fakeBunny();
        $victim = ArticleFactory::new()->published()->create();
        $victimImg = ArticleImageFactory::new()->forArticle($victim)->create();
        // corrupt/legacy row that points at ANOTHER article's object
        $img = ArticleImageFactory::new()->forArticle($this->article)->create([
            'bunny_storage_path' => $victimImg->bunny_storage_path, 'bunny_url' => $victimImg->bunny_url,
        ]);

        $this->as($this->author)->post($this->url(suffix: $img->id.'/replace'), ['image' => $this->upload($this->imageBytes())], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, $this->bunnyRequests('PUT'));
        $this->assertCount(0, $this->bunnyRequests('DELETE'), 'foreign object must survive');
        $this->assertNotNull($victimImg->fresh());
    }

    public function test_replace_rejects_invalid_file_and_needs_permission(): void
    {
        $this->fakeBunny();
        $img = ArticleImageFactory::new()->forArticle($this->article)->create();
        $this->as($this->author)->post($this->url(suffix: $img->id.'/replace'), ['image' => $this->upload('not an image', 'x.jpg')], ['Accept' => 'application/json'])->assertStatus(400);
        $this->post($this->url(suffix: $img->id.'/replace'), [], ['Accept' => 'application/json'])->assertStatus(400)->assertJsonPath('image.0', 'No file was submitted.');
        $this->as(UserFactory::new()->reporter()->create())->post($this->url(suffix: $img->id.'/replace'), ['image' => $this->upload($this->imageBytes())], ['Accept' => 'application/json'])->assertStatus(404);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- delete

    public function test_delete_removes_row_and_exact_bunny_object(): void
    {
        $this->fakeBunny();
        $img = ArticleImageFactory::new()->forArticle($this->article)->featured()->create();
        $keep = ArticleImageFactory::new()->forArticle($this->article)->create();

        $this->as($this->author)->deleteJson($this->url(suffix: $img->id.'/'))->assertNoContent();
        $this->assertNull(ArticleImage::query()->find($img->id));
        $del = $this->bunnyRequests('DELETE');
        $this->assertCount(1, $del);
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$img->bunny_storage_path, $del[0]->url());
        $this->assertNotNull($keep->fresh());
        $this->assertTrue($keep->fresh()->is_featured, 'next image is promoted so the article keeps exactly one featured image');
    }

    public function test_delete_tolerates_bunny_404_and_bunny_errors(): void
    {
        $this->fakeBunny(delete: 404);
        $a = ArticleImageFactory::new()->forArticle($this->article)->create();
        $this->as($this->author)->deleteJson($this->url(suffix: $a->id.'/'))->assertNoContent();
        $this->assertNull(ArticleImage::query()->find($a->id));
    }

    public function test_delete_still_succeeds_when_bunny_delete_errors(): void
    {
        $this->fakeBunny(delete: 500);
        $a = ArticleImageFactory::new()->forArticle($this->article)->create();
        $this->as($this->author)->deleteJson($this->url(suffix: $a->id.'/'))->assertNoContent();
        $this->assertNull(ArticleImage::query()->find($a->id));
    }

    public function test_delete_cross_article_and_shared_paths_are_never_removed_from_bunny(): void
    {
        $this->fakeBunny();
        $victim = ArticleFactory::new()->published()->create();
        $v = ArticleImageFactory::new()->forArticle($victim)->create();
        $mineForeign = ArticleImageFactory::new()->forArticle($this->article)->create(['bunny_storage_path' => $v->bunny_storage_path]);
        $mineTraversal = ArticleImageFactory::new()->forArticle($this->article)->create(['bunny_storage_path' => 'articles/'.$this->article->slug.'/../'.$victim->slug.'/x.jpg']);
        $adPath = 'advertisements/'.str_repeat('a', 32).'.jpg';
        AdvertisementFactory::new()->create(['bunny_storage_path' => $adPath]);
        $mineAd = ArticleImageFactory::new()->forArticle($this->article)->create(['bunny_storage_path' => $adPath]);

        $this->as($this->author);
        foreach ([$mineForeign, $mineTraversal, $mineAd] as $row) {
            $this->deleteJson($this->url(suffix: $row->id.'/'))->assertNoContent();
        }
        $this->assertCount(0, $this->bunnyRequests('DELETE'));
    }

    public function test_other_users_cannot_delete_images_and_nothing_is_sent(): void
    {
        $this->fakeBunny();
        $img = ArticleImageFactory::new()->forArticle($this->article)->create();
        $this->deleteJson($this->url(suffix: $img->id.'/'))->assertStatus(401);
        $this->as(UserFactory::new()->create())->deleteJson($this->url(suffix: $img->id.'/'))->assertStatus(403);
        $this->as(UserFactory::new()->reporter()->create())->deleteJson($this->url(suffix: $img->id.'/'))->assertStatus(404);
        $this->assertNotNull($img->fresh());
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- listeners

    public function test_deleting_an_article_removes_its_bunny_objects_best_effort(): void
    {
        $this->fakeBunny();
        $a = ArticleImageFactory::new()->forArticle($this->article)->featured()->create();
        $b = ArticleImageFactory::new()->forArticle($this->article)->create();
        $foreignArticle = ArticleFactory::new()->create();
        $foreign = ArticleImageFactory::new()->forArticle($foreignArticle)->create();

        $this->article->delete();

        $urls = array_map(fn (Request $r) => $r->url(), $this->bunnyRequests('DELETE'));
        sort($urls);
        $expect = ['https://storage.bunnycdn.com/testzone/'.$a->bunny_storage_path, 'https://storage.bunnycdn.com/testzone/'.$b->bunny_storage_path];
        sort($expect);
        $this->assertSame($expect, $urls);
        $this->assertSame(0, ArticleImage::query()->where('article_id', $this->article->id)->count());
        $this->assertNotNull($foreign->fresh());
    }

    public function test_article_delete_is_not_blocked_by_bunny_failure(): void
    {
        $this->fakeBunny(delete: 500);
        ArticleImageFactory::new()->forArticle($this->article)->create();
        $this->assertTrue($this->article->delete());
        $this->assertNull(Article::query()->find($this->article->id));
    }

    public function test_category_image_objects_are_removed_on_replace_and_delete(): void
    {
        $this->fakeBunny();
        $cat = CategoryFactory::new()->create(['image_url' => 'https://test-cdn.b-cdn.net/categories/one.jpg', 'image_storage_path' => 'categories/one.jpg']);

        $cat->update(['image_storage_path' => 'categories/two.jpg', 'image_url' => 'https://test-cdn.b-cdn.net/categories/two.jpg']);
        $cat->refresh()->delete();
        $urls = array_map(fn (Request $r) => $r->url(), $this->bunnyRequests('DELETE'));
        $this->assertSame([
            'https://storage.bunnycdn.com/testzone/categories/one.jpg',
            'https://storage.bunnycdn.com/testzone/categories/two.jpg',
        ], $urls);
    }

    public function test_category_without_own_upload_path_never_touches_bunny(): void
    {
        $this->fakeBunny();
        $cat = CategoryFactory::new()->create(['image_url' => 'https://elsewhere.example/x.jpg', 'image_storage_path' => null]);
        $cat->update(['image_url' => 'https://elsewhere.example/y.jpg']);
        $cat->delete();
        Http::assertNothingSent();
    }

    public function test_no_response_ever_contains_the_access_key(): void
    {
        $this->fakeBunny(put: 401);
        $res = $this->as($this->author)->postImage();
        $this->assertStringNotContainsString(self::BUNNY_KEY, $res->getContent());
        $this->assertStringNotContainsString('AccessKey', $res->getContent());
    }
}
