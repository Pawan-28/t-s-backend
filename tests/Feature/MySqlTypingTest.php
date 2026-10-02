<?php

namespace Tests\Feature;

use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PlagiarismCheckResult;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Factories\AdvertisementFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SubscriptionFactory;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/**
 * JSON scalar TYPES must equal the PostgreSQL/Django contract: booleans are true/false (never 0/1), money is a
 * DECIMAL STRING with exactly two decimals, counts are integers, scores are floats. MySQL returns tinyint(1),
 * DECIMAL and SUM() as ints/strings; everything is cast at the model/resource layer and asserted here.
 */
class MySqlTypingTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    public function test_money_is_a_two_decimal_string_in_plans_payments_and_the_admin_plan_api(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([[499, '499.00'], ['499.5', '499.50'], [0.5, '0.50'], [19.99, '19.99'], [0.29, '0.29'], [1.15, '1.15'], [99999999.99, '99999999.99']] as [$in, $out]) {
            $r = $this->as($admin)->postJson('/api/subscriptions/admin/plans/', ['name' => 'Plan '.$out, 'price_amount' => $in, 'duration_days' => 30])->assertCreated();
            $this->assertSame($out, $r->json('price_amount'), "price {$in}");
            $this->assertStringContainsString('"price_amount":"'.$out.'"', $r->getContent());
            $this->assertSame($out, $this->as($admin)->getJson('/api/subscriptions/admin/plans/'.$r->json('id').'/')->json('price_amount'));
        }
        $this->as(null)->getJson('/api/subscriptions/plans/')->assertOk()->assertJsonPath('results.0.price_amount', '0.29')->assertJsonPath('results.1.price_amount', '0.50');
        $this->as($admin)->postJson('/api/subscriptions/admin/plans/', ['name' => 'x', 'price_amount' => '1.005', 'duration_days' => 3])->assertStatus(400);

        $sub = SubscriptionFactory::new()->create();
        PaymentFactory::new()->forSubscription($sub)->create(['amount' => 499]);
        PaymentFactory::new()->forSubscription($sub)->create(['amount' => '1234567.80']);
        $rows = $this->as($admin)->getJson('/api/subscriptions/admin/payments/')->assertOk()->json('results');
        $this->assertEqualsCanonicalizing(['499.00', '1234567.80'], array_column($rows, 'amount'));
        foreach ($rows as $row) {
            $this->assertIsString($row['amount']);
            $this->assertIsInt($row['id']);
            $this->assertIsInt($row['subscription_id']);
        }
    }

    public function test_booleans_and_integers_in_resources_are_real_json_types(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $plan = SubscriptionPlanFactory::new()->create();
        $sub = SubscriptionFactory::new()->active(10)->create(['plan_id' => $plan->id]);

        $me = $this->as($admin)->getJson('/api/auth/me/')->assertOk()->json();
        $this->assertTrue($me['is_active']);

        $planJson = $this->as($admin)->getJson('/api/subscriptions/admin/plans/'.$plan->id.'/')->json();
        $this->assertTrue($planJson['is_active']);
        $this->assertSame(30, $planJson['duration_days']);

        $subJson = $this->as($sub->user)->getJson('/api/subscriptions/me/')->json();
        $this->assertTrue($subJson['is_active_now']);
        $this->assertSame(30, $subJson['plan']['duration_days']);

        $industry = $this->as(null)->getJson('/api/industries/')->json('0');
        $this->assertTrue($industry['is_active']);
        $this->assertIsInt($industry['display_order']);
        $subcat = $this->as(null)->getJson('/api/subcategories/')->json('0');
        $this->assertTrue($subcat['is_active']);
        $this->assertTrue($subcat['category']['is_active']);
        $this->assertIsInt($subcat['display_order']);

        Notification::query()->create(['recipient_id' => $admin->id, 'notification_type' => 'ARTICLE_PUBLISHED', 'message' => 'm', 'is_read' => false]);
        $n = $this->as($admin)->getJson('/api/notifications/')->assertOk()->json('results.0');
        $this->assertFalse($n['is_read']);
        $this->assertSame(1, $this->as($admin)->getJson('/api/notifications/unread-count/')->json('unread_count'));

        AdvertisementFactory::new()->create(['priority' => 7, 'is_active' => true]);
        $ad = $this->as($admin)->getJson('/api/advertisements/')->assertOk()->json('0') ?? $this->as($admin)->getJson('/api/advertisements/')->json('results.0');
        $this->assertTrue($ad['is_active']);
        $this->assertSame(7, $ad['priority']);
        $pub = $this->as(null)->getJson('/api/advertisements/active/')->assertOk()->json();
        $this->assertSame(7, ($pub['results'][0] ?? $pub[0])['priority']);

        $art = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id]);
        $item = $this->as(null)->getJson('/api/articles/')->json('results.0');
        $this->assertIsBool($item['is_locked']);
        $this->assertIsInt($item['id']);
        $this->assertSame([], $item['faqs']);
        $this->assertNotNull($art->id);
    }

    public function test_image_metadata_ints_and_featured_bool(): void
    {
        $t = $this->tree();
        $art = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id]);
        ArticleImage::query()->create([
            'article_id' => $art->id, 'uploaded_by_id' => $art->author_id, 'bunny_url' => 'https://x/y.jpg', 'bunny_storage_path' => 'articles/'.$art->slug.'/a.jpg',
            'is_featured' => true, 'display_order' => 2, 'metadata' => ['file_size_bytes' => '1234', 'width' => 640, 'height' => 480, 'checksum' => 'abc', 'content_type' => 'image/jpeg', 'original_filename' => 'é.jpg'],
        ]);
        $r = $this->as(null)->getJson('/api/articles/'.$art->slug.'/images/')->assertOk();
        $img = $r->json('0') ?? $r->json('results.0');
        $this->assertTrue($img['is_featured']);
        $this->assertSame(2, $img['display_order']);
        $this->assertSame(1234, $img['metadata']['file_size_bytes']);
        $this->assertSame(640, $img['metadata']['width']);
        $this->assertSame('é.jpg', $img['metadata']['original_filename']);
    }

    public function test_scores_are_floats_and_json_columns_keep_their_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $art = Article::factory()->create();
        AiAnalysisResult::query()->create([
            'article_id' => $art->id, 'provider' => 'OPENAI', 'model_name' => 'm', 'status' => 'COMPLETED', 'error_message' => '',
            'readability_score' => 72.5, 'grammar_issues' => [['text' => 'त्रुटि', 'suggestion' => 'ठीक']], 'seo_suggestions' => ['a', 'b'],
            'ai_content_likelihood' => 0.1234567891, 'ai_content_rationale' => 'ok',
        ]);
        PlagiarismCheckResult::query()->create([
            'article_id' => $art->id, 'provider' => 'COPYLEAKS', 'scan_id' => 'scan1', 'status' => 'COMPLETED', 'error_message' => '',
            'similarity_score' => 12.345678901234, 'matches' => [['source_url' => 'https://a/b?x=1&y=2', 'similarity_percent' => 3.5, 'matched_text' => 'é']],
        ]);
        $a = $this->as($admin)->getJson('/api/ai/analysis-results/')->assertOk()->json('results.0');
        $this->assertSame(72.5, $a['readability_score']);
        $this->assertSame(0.1234567891, $a['ai_content_likelihood'], 'double precision, not FLOAT(24)');
        $this->assertSame([['text' => 'त्रुटि', 'suggestion' => 'ठीक']], $a['grammar_issues']);
        $this->assertSame(['a', 'b'], $a['seo_suggestions']);
        $p = $this->as($admin)->getJson('/api/ai/plagiarism-results/')->assertOk()->json('results.0');
        $this->assertSame(12.345678901234, $p['similarity_score']);
        $this->assertSame(3.5, $p['matches'][0]['similarity_percent']);
        $this->assertSame([], $this->as($admin)->getJson('/api/ai/analysis-results/'.$a['id'].'/')->json('raw_response') ?? []);
    }

    public function test_analytics_reports_return_ints_not_decimal_strings(): void
    {
        $admin = User::factory()->admin()->create();
        $t = $this->tree();
        $art = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'author_id' => $admin->id]);
        DB::table('article_daily_views')->insert(['article_id' => $art->id, 'date' => now()->toDateString(), 'views' => 42, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['total_views' => 42], $this->as($admin)->getJson('/api/analytics/overview/')->json());
        $pop = $this->as($admin)->getJson('/api/analytics/articles/popular/')->json('0');
        $this->assertSame(42, $pop['total_views']);
        foreach (['industries' => 'industry_id', 'categories' => 'category_id', 'subcategories' => 'subcategory_id'] as $ep => $idKey) {
            $row = $this->as($admin)->getJson('/api/analytics/'.$ep.'/')->assertOk()->json('0');
            $this->assertSame(42, $row['total_views'], $ep);
            $this->assertIsInt($row[$idKey], $ep);
        }
        $rep = $this->as($admin)->getJson('/api/analytics/reporters/')->assertOk()->json('0');
        foreach (['reporter_id', 'total_articles', 'submitted', 'published', 'pending_review', 'rejected', 'changes_requested'] as $k) {
            $this->assertIsInt($rep[$k], $k);
        }
        $this->assertSame(1, $rep['published']);
        $pub = $this->as($admin)->getJson('/api/analytics/publishing/')->json();
        $this->assertSame(1, $pub['published_count']);
        $this->assertSame(0, $pub['scheduled_count']);
        $this->assertIsInt($pub['published_last_n_days'][0]['count']);
        $this->assertIsString($pub['published_last_n_days'][0]['date']);
        $vot = $this->as($admin)->getJson('/api/analytics/views-over-time/')->json('0');
        $this->assertSame(42, $vot['views']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $vot['date']);
        $dv = $this->as($admin)->getJson('/api/analytics/admin/article-daily-views/')->assertOk()->json('results.0');
        $this->assertSame(42, $dv['views']);
        $this->assertSame(now()->toDateString(), $dv['date']);
        $this->assertSame(0, $this->as($admin)->getJson('/api/subscriptions/active-subscribers/')->json('active_subscribers'));
    }

    public function test_plan_and_payment_models_never_expose_floats(): void
    {
        $plan = SubscriptionPlan::query()->create(['name' => 'p', 'slug' => 'p', 'description' => '', 'price_amount' => 19.99, 'price_currency' => 'INR', 'duration_days' => 1]);
        $this->assertSame('19.99', $plan->refresh()->price_amount);
        $sub = SubscriptionFactory::new()->create();
        $pay = Payment::query()->create(['subscription_id' => $sub->id, 'user_id' => $sub->user_id, 'razorpay_order_id' => 'o1', 'amount' => '0.10', 'currency' => 'INR', 'status' => 'CREATED']);
        $this->assertSame('0.10', $pay->refresh()->amount);
        $this->assertSame('0.30', number_format((float) '0.10' + (float) '0.20', 2, '.', ''), 'sanity: float sum is only safe after rounding; the app never sums floats');
    }
}
