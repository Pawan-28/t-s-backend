<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus as S;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\Industry;
use Database\Factories\AdvertisementFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/**
 * STRICT_TRANS_TABLES turns silent truncation / bad values into errors (HTTP 500 if the API does not validate first).
 * Every legal-but-extreme input the API accepts must be stored, and every illegal one must be a 4xx.
 */
class MySqlStrictModeTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
    }

    private function payload(array $over = []): array
    {
        return $over + ['title' => 'Strict', 'content' => '<p>body</p>', 'subcategory_slug' => $this->subcategory->slug];
    }

    public function test_maximum_length_multibyte_fields_fit_their_columns(): void
    {
        $emoji255 = str_repeat('😀', 255);
        $r = $this->as($this->admin)->postJson('/api/articles/', $this->payload([
            'title' => $emoji255, 'location_name' => str_repeat('मुं', 66), 'excerpt' => str_repeat('अ', 70000), // > 65,535 BYTES: needs MEDIUMTEXT
        ]))->assertCreated();
        $a = Article::query()->findOrFail($r->json('id'));
        $this->assertSame($emoji255, $a->title);
        $this->assertSame(70000, mb_strlen($a->excerpt));
        $this->assertSame(str_repeat('मुं', 66), $a->location_name);
        $this->assertSame(strlen($a->slug) >= 1000 ? 0 : 1, 1, 'slug stays within its varchar');

        // 255 emoji title through the whole workflow (notification message is varchar(500))
        $slug = $a->slug;
        $this->act($this->admin, $slug, 'publish')->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('recipient_id', $a->author_id)->count());

        $long = str_repeat('👍', 100000);
        foreach (['description' => $long] as $k => $v) {
            $this->as($this->admin)->postJson('/api/industries/', ['name' => 'Big desc', $k => $v])->assertCreated();
        }
        $this->assertSame(100000, mb_strlen(Industry::query()->where('name', 'Big desc')->value('description')));
    }

    public function test_plan_description_is_unbounded_text_and_is_stored(): void
    {
        $r = $this->as($this->admin)->postJson('/api/subscriptions/admin/plans/', ['name' => 'Long', 'description' => str_repeat('विवरण ', 30000), 'price_amount' => '10.00', 'duration_days' => 30])->assertCreated();
        $this->assertSame(mb_strlen(trim(str_repeat('विवरण ', 30000))), mb_strlen($r->json('description')));
    }

    public function test_out_of_range_integers_are_validation_errors_not_500s(): void
    {
        $this->as($this->admin)->postJson('/api/industries/', ['name' => 'I1', 'display_order' => 2147483648])->assertStatus(400);
        $this->as($this->admin)->postJson('/api/industries/', ['name' => 'I2', 'display_order' => -1])->assertStatus(400);
        $this->as($this->admin)->postJson('/api/industries/', ['name' => 'I3', 'display_order' => 2147483647])->assertCreated();
        $this->as($this->admin)->postJson('/api/subscriptions/admin/plans/', ['name' => 'P', 'price_amount' => '100000000.00', 'duration_days' => 1])->assertStatus(400);
        $this->as($this->admin)->postJson('/api/subscriptions/admin/plans/', ['name' => 'P', 'price_amount' => '1', 'duration_days' => 4294967296])->assertStatus(400);
        $this->as($this->admin)->postJson('/api/subscriptions/admin/plans/', ['name' => 'P', 'price_amount' => '1', 'duration_days' => 4294967295])->assertCreated();
        $ad = AdvertisementFactory::new()->create();
        $this->as($this->admin)->patchJson('/api/advertisements/'.$ad->id.'/', ['priority' => 2147483648])->assertStatus(400);
        $this->as($this->admin)->patchJson('/api/advertisements/'.$ad->id.'/', ['priority' => 2147483647])->assertOk();
    }

    public function test_impossible_or_out_of_range_datetimes_are_client_errors_never_500(): void
    {
        $a = $this->article(S::APPROVED);
        // UTC-based year-9999 instants overflow DATETIME only when the app zone is east of UTC (Asia/Kolkata: year 10000).
        $eastOfUtc = now()->utcOffset() > 0;
        foreach (['9999-12-31T23:59:59Z', '9999-12-31T23:59:59+00:00', '9999-12-31T23:59:59-12:00', '0000-00-00T00:00:00', '0000-01-01T00:00', '0001-01-01T00:00:00Z'] as $when) {
            $status = $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => $when])->status();
            $mayFit = str_starts_with($when, '9999') && ! $eastOfUtc;
            $this->assertContains($status, $mayFit ? [200, 400, 422] : [400, 422], "scheduled_for {$when} -> {$status}");
            if ($mayFit && $status === 200) {
                $this->act($this->admin, $a->slug, 'cancel-schedule')->assertOk();
            }
        }
        // last representable instant of the app zone is accepted
        $this->act($this->admin, $a->slug, 'schedule', ['scheduled_for' => '9999-12-31T23:59:59'])->assertOk();

        $ad = AdvertisementFactory::new()->create();
        foreach (['0000-00-00T00:00:00', '0000-01-01T00:00', '0999-12-31T23:59:59', '9999-12-31T23:59:59Z', '9999-12-31T23:59:59-12:00'] as $when) {
            $status = $this->as($this->admin)->patchJson('/api/advertisements/'.$ad->id.'/', ['start_at' => $when])->status();
            $this->assertContains($status, [200, 400, 422], "start_at {$when} -> {$status}");
        }
        $this->as($this->admin)->patchJson('/api/advertisements/'.$ad->id.'/', ['start_at' => '1000-01-01T00:00:00', 'end_at' => '9999-12-31T23:59:59'])->assertOk();
        $this->assertSame('1000-01-01', Advertisement::query()->find($ad->id)->start_at->format('Y-m-d'));

        $admin = $this->admin;
        foreach (['0000-00-00', '9999-99-99', '99999-01-01', '-1-01-01'] as $q) {
            $this->assertContains($this->as($admin)->getJson('/api/ai/analysis-results/?created_after='.urlencode($q))->status(), [200, 400, 422], $q);
            $this->assertContains($this->as($admin)->getJson('/api/analytics/admin/article-daily-views/?date_after='.urlencode($q))->status(), [200, 400, 422], $q);
        }
    }

    public function test_invalid_utf8_input_is_a_client_error_not_a_500(): void
    {
        // Query strings / form fields can carry raw invalid bytes (JSON bodies cannot: json_decode rejects them).
        foreach (['/api/articles/?search=%FF%FE', '/api/search/?q=%C3%28', '/api/tags/?search=%80'] as $url) {
            $this->assertLessThan(500, $this->as(null)->getJson($url)->status(), $url);
        }
        $this->as($this->admin)->post('/api/tags/', ['name' => "bad\xFF\xFEname"], ['Accept' => 'application/json'])->assertStatus(400);
        $this->assertLessThan(500, $this->as($this->admin)->post('/api/articles/', $this->payload(['title' => "t\xC3\x28"]), ['Accept' => 'application/json'])->status());
    }
}
