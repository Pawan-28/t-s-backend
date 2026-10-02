<?php

namespace Tests\Feature;

use App\Enums\AdPlacement;
use App\Models\Advertisement;
use App\Models\User;
use Database\Factories\AdvertisementFactory;
use Database\Factories\ArticleFactory;
use Database\Factories\ArticleImageFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MediaTestHelpers;
use Tests\TestCase;

class AdsApiTest extends TestCase
{
    use MediaTestHelpers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureBunny();
        $this->admin = UserFactory::new()->admin()->create();
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAsUser($u);
    }

    private function form(array $over = [], bool $withFile = true): array
    {
        return array_merge([
            'name' => 'Diwali campaign',
            'placement' => 'HOME_MIDDLE',
            'target_url' => 'https://example.com/promo',
            'start_at' => now()->subHour()->toIso8601String(),
            'end_at' => now()->addDay()->toIso8601String(),
            'is_active' => 'true',
            'priority' => '5',
        ], $withFile ? ['creative_upload' => $this->upload($this->imageBytes(728, 90))] : [], $over);
    }

    private function create(array $over = [], bool $withFile = true)
    {
        return $this->post('/api/advertisements/', $this->form($over, $withFile), ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------- public active

    public function test_public_active_returns_only_live_ads_of_the_placement_as_plain_array(): void
    {
        AdvertisementFactory::new()->create(['name' => 'live-low', 'priority' => 1]);
        AdvertisementFactory::new()->create(['name' => 'live-high', 'priority' => 9]);
        AdvertisementFactory::new()->expired()->create(['name' => 'expired']);
        AdvertisementFactory::new()->upcoming()->create(['name' => 'upcoming']);
        AdvertisementFactory::new()->inactive()->create(['name' => 'disabled']);
        AdvertisementFactory::new()->placement(AdPlacement::HOME_SIDEBAR)->create(['name' => 'sidebar']);

        $res = $this->getJson('/api/advertisements/active/?placement=HOME_MIDDLE');
        $res->assertOk();
        $this->assertTrue(array_is_list($res->json()));
        $this->assertSame(['live-high', 'live-low'], array_column($res->json(), 'name'), 'priority desc, only live');

        $all = $this->getJson('/api/advertisements/active/')->json();
        $this->assertEqualsCanonicalizing(['live-high', 'live-low', 'sidebar'], array_column($all, 'name'));
    }

    public function test_public_shape_hides_admin_internals_and_target_url_is_empty_string_when_blank(): void
    {
        AdvertisementFactory::new()->create(['target_url' => '']);
        $row = $this->getJson('/api/advertisements/active/')->json()[0];
        $this->assertSame(['id', 'name', 'placement', 'image_url', 'target_url', 'priority'], array_keys($row));
        $this->assertSame('', $row['target_url']);
    }

    public function test_active_endpoint_is_public_and_ties_break_by_newest(): void
    {
        $old = AdvertisementFactory::new()->create(['name' => 'old', 'priority' => 3, 'created_at' => now()->subDays(2)]);
        $new = AdvertisementFactory::new()->create(['name' => 'new', 'priority' => 3, 'created_at' => now()->subDay()]);
        $this->assertSame([$new->id, $old->id], array_column($this->getJson('/api/advertisements/active')->assertOk()->json(), 'id'));
    }

    public function test_home_top_is_still_a_valid_public_placement(): void
    {
        AdvertisementFactory::new()->placement(AdPlacement::HOME_TOP)->create(['name' => 'top']);
        $this->getJson('/api/advertisements/active/?placement=HOME_TOP')->assertOk()->assertJsonCount(1)->assertJsonPath('0.placement', 'HOME_TOP');
    }

    public function test_ad_ending_exactly_now_or_started_in_the_past_boundaries(): void
    {
        AdvertisementFactory::new()->create(['name' => 'ends-soon', 'start_at' => now()->subHour(), 'end_at' => now()->addSeconds(30)]);
        AdvertisementFactory::new()->create(['name' => 'ended-1s-ago', 'start_at' => now()->subHour(), 'end_at' => now()->subSecond()]);
        $this->assertSame(['ends-soon'], array_column($this->getJson('/api/advertisements/active/')->json(), 'name'));
    }

    // ------------------------------------------------------------- admin auth

    public function test_admin_endpoints_require_admin(): void
    {
        $ad = AdvertisementFactory::new()->create();
        $this->getJson('/api/advertisements/')->assertStatus(401);
        $this->postJson('/api/advertisements/', [])->assertStatus(401);
        foreach ([UserFactory::new()->create(), UserFactory::new()->reporter()->create()] as $u) {
            $this->as($u);
            $this->getJson('/api/advertisements/')->assertStatus(403);
            $this->getJson("/api/advertisements/{$ad->id}/")->assertStatus(403);
            $this->postJson('/api/advertisements/', [])->assertStatus(403);
            $this->patchJson("/api/advertisements/{$ad->id}/", ['is_active' => false])->assertStatus(403);
            $this->deleteJson("/api/advertisements/{$ad->id}/")->assertStatus(403);
        }
        $this->assertTrue($ad->fresh()->is_active);
    }

    // ------------------------------------------------------------- create

    public function test_create_uploads_creative_to_bunny_and_returns_django_shape(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->admin)->create();
        $res->assertCreated()->assertJsonStructure(['id', 'name', 'placement', 'creative_type', 'image_url', 'target_url', 'start_at', 'end_at', 'is_active', 'priority', 'created_at', 'updated_at']);
        $this->assertSame('IMAGE', $res->json('creative_type'));
        $this->assertSame(5, $res->json('priority'));
        $this->assertTrue($res->json('is_active'));

        $ad = Advertisement::query()->findOrFail($res->json('id'));
        $this->assertMatchesRegularExpression('#^advertisements/[0-9a-f]{32}\.jpg$#', $ad->bunny_storage_path);
        $this->assertSame('https://test-cdn.b-cdn.net/'.$ad->bunny_storage_path, $ad->image_url);
        $put = $this->bunnyRequests('PUT');
        $this->assertCount(1, $put);
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$ad->bunny_storage_path, $put[0]->url());
        $this->assertArrayNotHasKey('bunny_storage_path', $res->json());
        $this->assertArrayNotHasKey('creative_upload', $res->json());
    }

    public function test_every_placement_including_home_top_is_accepted(): void
    {
        $this->fakeBunny();
        $this->as($this->admin);
        foreach (['HOME_TOP', 'HOME_MIDDLE', 'HOME_SIDEBAR', 'HOME_BOTTOM', 'ARTICLE_TOP', 'ARTICLE_MIDDLE', 'ARTICLE_BOTTOM'] as $p) {
            $this->create(['placement' => $p])->assertCreated()->assertJsonPath('placement', $p);
        }
        $this->create(['placement' => 'SIDEBAR'])->assertStatus(400)->assertJsonPath('placement.0', '"SIDEBAR" is not a valid choice.');
        $this->assertSame(7, Advertisement::query()->count());
    }

    public function test_target_url_is_optional_blank_missing_or_valid(): void
    {
        $this->fakeBunny();
        $this->as($this->admin);
        $blank = $this->create(['target_url' => ''])->assertCreated();
        $this->assertSame('', $blank->json('target_url'));

        $form = $this->form();
        unset($form['target_url']);
        $missing = $this->post('/api/advertisements/', $form, ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('', $missing->json('target_url'));
    }

    public function test_provided_target_url_is_still_validated(): void
    {
        $this->fakeBunny();
        $this->as($this->admin);
        $this->create(['target_url' => 'javascript:alert(1)'])->assertStatus(400)->assertJsonPath('target_url.0', 'Enter a valid URL.')
            ->assertJsonPath('target_url.1', "Unsafe or unsupported URL scheme 'javascript'. Only http:// and https:// are allowed.");
        $this->create(['target_url' => 'ftp://example.com/x'])->assertStatus(400)
            ->assertJsonPath('target_url.0', "Unsafe or unsupported URL scheme 'ftp'. Only http:// and https:// are allowed.");
        $this->create(['target_url' => 'not a url'])->assertStatus(400);
        $this->assertSame(0, Advertisement::query()->count());
        Http::assertNothingSent();
    }

    public function test_create_validation_messages(): void
    {
        $this->fakeBunny();
        $this->as($this->admin);
        $this->post('/api/advertisements/', [], ['Accept' => 'application/json'])->assertStatus(400)
            ->assertJsonPath('name.0', 'This field is required.')
            ->assertJsonPath('placement.0', 'This field is required.')
            ->assertJsonPath('start_at.0', 'This field is required.')
            ->assertJsonPath('end_at.0', 'This field is required.');

        $this->create(['end_at' => now()->subDay()->toIso8601String()])->assertStatus(400)->assertExactJson(['end_at' => ['End date must be after the start date.']]);
        $this->create(['start_at' => 'yesterday'])->assertStatus(400)
            ->assertJsonPath('start_at.0', 'Datetime has wrong format. Use one of these formats instead: YYYY-MM-DDThh:mm[:ss[.uuuuuu]][+HH:MM|-HH:MM|Z].');
        $this->create(['priority' => '-3'])->assertStatus(400)->assertJsonPath('priority.0', 'Ensure this value is greater than or equal to 0.');
        $this->create(['is_active' => 'perhaps'])->assertStatus(400)->assertJsonPath('is_active.0', '"perhaps" is not a valid boolean.');
        $this->create(['name' => str_repeat('n', 151)])->assertStatus(400)->assertJsonPath('name.0', 'Ensure this field has no more than 150 characters.');
    }

    public function test_creative_is_required_on_create_and_must_be_a_real_image(): void
    {
        $this->fakeBunny();
        $this->as($this->admin);
        $this->create(withFile: false)->assertStatus(400)->assertExactJson(['creative_upload' => ['A creative image upload is required.']]);
        $this->create(['creative_upload' => $this->upload('<?php evil();', 'banner.png', 'image/png')])->assertStatus(400)
            ->assertJsonPath('creative_upload.0', 'Upload a valid image. The file you uploaded was either not an image or a corrupted image.');
        $this->assertSame(0, Advertisement::query()->count());
        Http::assertNothingSent();
    }

    public function test_large_creative_is_resized_and_oversize_file_rejected(): void
    {
        $this->fakeBunny();
        $this->as($this->admin);
        $this->create(['creative_upload' => $this->upload($this->imageBytes(5000, 1000))])->assertCreated();
        $sent = $this->decodeBody($this->bunnyRequests('PUT')[0]);
        $this->assertSame([2000, 400], [$sent['w'], $sent['h']]);

        config(['portal.images.max_upload_mb' => 1]);
        $this->create(['creative_upload' => $this->upload($this->imageBytes(300, 300).str_repeat('A', 1048600))])->assertStatus(400)
            ->assertJsonPath('creative_upload.0', 'Image exceeds the maximum upload size of 1MB.');
    }

    public function test_bunny_failure_on_create_gives_502_and_no_row(): void
    {
        $this->fakeBunny(put: 500);
        $res = $this->as($this->admin)->create();
        $res->assertStatus(502);
        $this->assertSame(0, Advertisement::query()->count());
        $this->assertStringNotContainsString(self::BUNNY_KEY, $res->getContent());
    }

    public function test_db_failure_after_upload_cleans_the_object(): void
    {
        $this->fakeBunny();
        Advertisement::creating(fn () => throw new \RuntimeException('db'));
        $this->as($this->admin)->create()->assertStatus(500);
        $this->assertSame($this->bunnyRequests('PUT')[0]->url(), $this->bunnyRequests('DELETE')[0]->url());
    }

    // ------------------------------------------------------------- list / show / update / delete

    public function test_admin_list_is_paginated_filterable_and_includes_inactive_and_expired(): void
    {
        AdvertisementFactory::new()->count(3)->create();
        AdvertisementFactory::new()->inactive()->placement(AdPlacement::ARTICLE_TOP)->create();
        AdvertisementFactory::new()->expired()->create();

        $res = $this->as($this->admin)->getJson('/api/advertisements/')->assertOk();
        $res->assertJsonStructure(['count', 'next', 'previous', 'results' => [['id', 'name', 'placement', 'creative_type', 'image_url', 'target_url', 'start_at', 'end_at', 'is_active', 'priority', 'created_at', 'updated_at']]]);
        $this->assertSame(5, $res->json('count'));
        $this->assertSame(1, $this->getJson('/api/advertisements/?placement=ARTICLE_TOP')->json('count'));
        $this->assertSame(1, $this->getJson('/api/advertisements/?is_active=false')->json('count'));
        $this->getJson('/api/advertisements/?placement=NOPE')->assertStatus(400);
        $this->getJson('/api/advertisements/?page=9')->assertStatus(404)->assertJsonPath('detail', 'Invalid page.');
    }

    public function test_show_and_404(): void
    {
        $ad = AdvertisementFactory::new()->create();
        $this->as($this->admin)->getJson("/api/advertisements/{$ad->id}/")->assertOk()->assertJsonPath('id', $ad->id);
        $this->getJson('/api/advertisements/999999/')->assertStatus(404)->assertJsonPath('detail', 'Not found.');
    }

    public function test_patch_toggle_active_without_touching_creative(): void
    {
        $this->fakeBunny();
        $ad = AdvertisementFactory::new()->create();
        $this->as($this->admin)->patchJson("/api/advertisements/{$ad->id}/", ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->assertFalse($ad->fresh()->is_active);
        $this->assertSame([], $this->getJson('/api/advertisements/active/')->json());
        Http::assertNothingSent();
    }

    public function test_patch_can_clear_target_url_and_validates_dates_against_stored_values(): void
    {
        $ad = AdvertisementFactory::new()->create(['target_url' => 'https://example.com']);
        $this->as($this->admin)->patchJson("/api/advertisements/{$ad->id}/", ['target_url' => ''])->assertOk()->assertJsonPath('target_url', '');
        $this->patchJson("/api/advertisements/{$ad->id}/", ['end_at' => now()->subDays(5)->toIso8601String()])->assertStatus(400)
            ->assertJsonPath('end_at.0', 'End date must be after the start date.');
    }

    public function test_patch_with_new_creative_uploads_new_then_deletes_only_the_old_file(): void
    {
        $this->fakeBunny();
        $ad = AdvertisementFactory::new()->create();
        $other = AdvertisementFactory::new()->create();
        $old = $ad->bunny_storage_path;

        $this->as($this->admin)->call('PATCH', "/api/advertisements/{$ad->id}/", ['name' => 'renamed'], [], ['creative_upload' => $this->upload($this->imageBytes(800, 200))], ['HTTP_ACCEPT' => 'application/json'])
            ->assertOk()->assertJsonPath('name', 'renamed');

        $row = $ad->fresh();
        $this->assertNotSame($old, $row->bunny_storage_path);
        $this->assertStringStartsWith('advertisements/', $row->bunny_storage_path);
        $put = $this->bunnyRequests('PUT');
        $del = $this->bunnyRequests('DELETE');
        $this->assertCount(1, $put);
        $this->assertCount(1, $del);
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$old, $del[0]->url());
        $this->assertSame(['PUT', 'DELETE'], collect(Http::recorded())->map(fn ($p) => $p[0]->method())->all());
        $this->assertNotSame($other->bunny_storage_path, $row->bunny_storage_path);
    }

    public function test_replace_failure_keeps_old_creative(): void
    {
        $this->fakeBunny(put: 500);
        $ad = AdvertisementFactory::new()->create();
        $old = $ad->image_url;
        $this->as($this->admin)->call('PATCH', "/api/advertisements/{$ad->id}/", ['name' => 'x'], [], ['creative_upload' => $this->upload($this->imageBytes())], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(502);
        $this->assertSame($old, $ad->fresh()->image_url);
        $this->assertSame($ad->fresh()->name === 'x', false);
        $this->assertCount(0, $this->bunnyRequests('DELETE'));
    }

    public function test_pasted_or_imported_creative_without_storage_path_is_never_deleted_from_bunny(): void
    {
        $this->fakeBunny();
        $ad = AdvertisementFactory::new()->create(['bunny_storage_path' => '', 'image_url' => 'https://elsewhere.example/a.jpg']);
        $this->as($this->admin)->call('PATCH', "/api/advertisements/{$ad->id}/", [], [], ['creative_upload' => $this->upload($this->imageBytes())], ['HTTP_ACCEPT' => 'application/json'])->assertOk();
        $this->assertCount(0, $this->bunnyRequests('DELETE'), 'external image had no owned object to remove');
        // the freshly uploaded creative belongs to the ad now and is removed with it
        $this->deleteJson("/api/advertisements/{$ad->id}/")->assertNoContent();
        $this->assertCount(1, $this->bunnyRequests('DELETE'));

        $pasted = AdvertisementFactory::new()->create(['bunny_storage_path' => '', 'image_url' => 'https://elsewhere.example/b.jpg']);
        $this->deleteJson("/api/advertisements/{$pasted->id}/")->assertNoContent();
        $this->assertCount(1, $this->bunnyRequests('DELETE'));
    }

    public function test_put_requires_the_required_fields(): void
    {
        $ad = AdvertisementFactory::new()->create();
        $this->as($this->admin)->putJson("/api/advertisements/{$ad->id}/", ['name' => 'only name'])->assertStatus(400)
            ->assertJsonPath('placement.0', 'This field is required.');
    }

    public function test_delete_removes_row_and_bunny_creative(): void
    {
        $this->fakeBunny();
        $ad = AdvertisementFactory::new()->create();
        $this->as($this->admin)->deleteJson("/api/advertisements/{$ad->id}/")->assertNoContent();
        $this->assertNull(Advertisement::query()->find($ad->id));
        $del = $this->bunnyRequests('DELETE');
        $this->assertCount(1, $del);
        $this->assertSame('https://storage.bunnycdn.com/testzone/'.$ad->bunny_storage_path, $del[0]->url());
    }

    public function test_delete_survives_bunny_error_and_never_deletes_shared_or_foreign_paths(): void
    {
        $this->fakeBunny(delete: 500);
        $ad = AdvertisementFactory::new()->create();
        $this->as($this->admin)->deleteJson("/api/advertisements/{$ad->id}/")->assertNoContent();
        $this->assertNull(Advertisement::query()->find($ad->id));
    }

    public function test_delete_never_touches_article_image_objects(): void
    {
        $this->fakeBunny();
        $article = ArticleFactory::new()->create();
        $img = ArticleImageFactory::new()->forArticle($article)->create();
        $ad = AdvertisementFactory::new()->create(['bunny_storage_path' => $img->bunny_storage_path]);
        $shared = AdvertisementFactory::new()->create(['bunny_storage_path' => 'advertisements/'.str_repeat('b', 32).'.jpg']);
        $twin = AdvertisementFactory::new()->create(['bunny_storage_path' => $shared->bunny_storage_path]);

        $this->as($this->admin);
        $this->deleteJson("/api/advertisements/{$ad->id}/")->assertNoContent();
        $this->deleteJson("/api/advertisements/{$shared->id}/")->assertNoContent();
        $this->assertCount(0, $this->bunnyRequests('DELETE'));
        $this->assertNotNull($twin->fresh());
    }

    public function test_no_secret_in_any_ad_response(): void
    {
        $this->fakeBunny();
        $res = $this->as($this->admin)->create();
        $this->assertStringNotContainsString(self::BUNNY_KEY, $res->getContent());
        $this->assertStringNotContainsString('AccessKey', $res->getContent());
    }
}
