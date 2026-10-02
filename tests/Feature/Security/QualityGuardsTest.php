<?php

namespace Tests\Feature\Security;

use App\Enums\ArticleStatus;
use App\Models\AiAnalysisResult;
use App\Models\Article;
use App\Models\ArticleDailyView;
use App\Models\Notification;
use App\Models\PlagiarismCheckResult;
use App\Models\PublishingSchedule;
use App\Models\ReporterCategoryAssignment;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use App\Services\Media\ImageValidationException;
use App\Support\HtmlSanitizer;
use Database\Factories\AdvertisementFactory;
use Database\Factories\ArticleImageFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

class QualityGuardsTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    /** N+1 guard: every list/detail endpoint must eager-load everything it serialises. */
    public function test_list_endpoints_do_not_lazy_load_relations(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->reporter()->create();
        $sub = $this->subscriberWithPlan();
        $t = $this->tree();
        $tag = Tag::factory()->create();

        $articles = [];
        foreach (range(1, 3) as $i) {
            $a = Article::factory()->published()->create([
                'subcategory_id' => $t['subcategory']->id, 'author_id' => $reporter->id,
                'assigned_reporter_id' => $reporter->id, 'title' => "Story $i",
            ]);
            $a->tags()->sync([$tag->id]);
            ArticleImageFactory::new()->forArticle($a)->create();
            ArticleDailyView::query()->create(['article_id' => $a->id, 'date' => now()->toDateString(), 'views' => 5]);
            AiAnalysisResult::query()->create(['article_id' => $a->id, 'requested_by_id' => $admin->id, 'provider' => 'OPENAI', 'model_name' => 'm', 'status' => 'COMPLETED']);
            PlagiarismCheckResult::query()->create(['article_id' => $a->id, 'requested_by_id' => $admin->id, 'provider' => 'COPYLEAKS', 'scan_id' => "scan$i", 'status' => 'PENDING', 'matches' => []]);
            PublishingSchedule::query()->create(['article_id' => $a->id, 'scheduled_for' => now()->addDay(), 'scheduled_by_id' => $admin->id, 'status' => 'EXECUTED']);
            Notification::query()->create(['recipient_id' => $reporter->id, 'article_id' => $a->id, 'notification_type' => 'ARTICLE_PUBLISHED', 'message' => 'm', 'is_read' => false]);
            $articles[] = $a;
        }
        ReporterCategoryAssignment::query()->create(['reporter_id' => $reporter->id, 'category_id' => $t['category']->id, 'assigned_by_id' => $admin->id]);
        $planSub = SubscriptionFactory::new()->active()->create();
        PaymentFactory::new()->forSubscription($planSub)->create();
        AdvertisementFactory::new()->create();
        $slug = $articles[0]->slug;

        Model::preventLazyLoading();

        $calls = [
            [null, '/api/articles/'], [$sub, '/api/articles/'], [$admin, '/api/articles/'],
            [$sub, "/api/articles/$slug/"], [null, "/api/articles/$slug/related/"], [null, "/api/articles/$slug/images/"],
            [null, '/api/search/?q=story'], [null, '/api/search/?subcategory='.$t['subcategory']->slug],
            [$reporter, '/api/articles/mine/'], [$reporter, '/api/articles/assigned/'],
            [$admin, "/api/articles/$slug/review-history/"],
            [null, '/api/categories/'], [null, '/api/subcategories/'], [null, '/api/industries/'], [null, '/api/tags/'],
            [$reporter, '/api/notifications/'], [$admin, '/api/notifications/admin/'],
            [$admin, '/api/accounts/users/'], [$admin, '/api/accounts/security/outstanding-tokens/'],
            [$admin, '/api/reporters/assignments/'], [$admin, '/api/reporters/schedules/'],
            [$admin, '/api/subscriptions/admin/list/'], [$admin, '/api/subscriptions/admin/payments/'],
            [$admin, '/api/subscriptions/admin/otps/'], [$admin, '/api/advertisements/'], [null, '/api/advertisements/active/'],
            [$admin, '/api/ai/analysis-results/'], [$admin, '/api/ai/plagiarism-results/'],
            [$reporter, "/api/articles/$slug/ai-check/"], [$reporter, "/api/articles/$slug/plagiarism-check/"],
            [$admin, '/api/analytics/articles/popular/'], [$admin, '/api/analytics/admin/article-daily-views/'],
            [$admin, '/api/analytics/categories/'], [$admin, '/api/analytics/reporters/'],
        ];
        foreach ($calls as [$who, $url]) {
            $res = $this->as($who)->getJson($url);
            $this->assertSame(200, $res->status(), "$url => ".substr($res->getContent(), 0, 400));
        }
    }

    public function test_page_sizes_and_limits_are_bounded(): void
    {
        $t = $this->tree();
        Article::factory()->count(3)->published()->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Bound story']);

        $this->as(null)->getJson('/api/search/?q=bound&page_size=100000')->assertOk()->assertJsonCount(3, 'results');
        $this->assertLessThanOrEqual(50, config('portal.search_page_size_max'));
        $this->as(null)->getJson('/api/articles/?page=999999999999999999999')->assertStatus(404);
        $this->as(null)->getJson('/api/articles/?page=-1')->assertStatus(404);
        $this->as(null)->getJson('/api/articles/x/related/?limit=100000')->assertStatus(404);
        $admin = User::factory()->admin()->create();
        $this->as($admin)->getJson('/api/analytics/articles/popular/?limit=1000000')->assertOk();
        $this->as($admin)->getJson('/api/analytics/views-over-time/?days=100000')->assertOk();
    }

    public function test_ordering_and_filter_parameters_are_whitelisted_not_interpolated(): void
    {
        $this->tree();
        Article::factory()->published()->create();
        foreach (['created_at;drop table articles', '(select pg_sleep(5))', 'content', 'articles.content', '-password', "title'--"] as $bad) {
            $this->as(null)->getJson('/api/articles/?ordering='.urlencode($bad))->assertOk();
        }
        foreach (["'; drop table articles;--", '%', '_', '\\', "a'b\"c"] as $q) {
            $this->as(null)->getJson('/api/articles/?search='.urlencode($q))->assertOk();
            $this->as(null)->getJson('/api/search/?q='.urlencode($q))->assertOk();
            $this->as(null)->getJson('/api/tags/?search='.urlencode($q).'&ordering='.urlencode($q))->assertOk();
        }
        // a lone LIKE wildcard must not match everything
        $this->as(null)->getJson('/api/articles/?search='.urlencode('%%%'))->assertOk()->assertJsonPath('count', 0);
        $this->as(null)->getJson('/api/articles/?search=_')->assertOk()->assertJsonPath('count', 0);
        $this->assertSame(1, Article::query()->count());
    }

    public function test_decompression_bomb_is_rejected_before_decoding(): void
    {
        $old = ini_get('memory_limit');
        // Relative to what this process already uses (a fixed 128M cannot be set once usage is higher, e.g. on MySQL 8 runs).
        ini_set('memory_limit', (string) (memory_get_usage(true) + 64 * 1024 * 1024));
        try {
            // A ~70-byte PNG that CLAIMS 7000x7000 px (49M px, under the pixel cap, far above the memory budget).
            $ihdr = pack('NN', 7000, 7000)."\x08\x06\x00\x00\x00";
            $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
            $this->expectException(ImageValidationException::class);
            $this->expectExceptionMessage('too large');
            app(ImageProcessor::class)->process($png);
        } finally {
            ini_set('memory_limit', (string) $old);
        }
    }

    public function test_svg_and_polyglot_uploads_are_not_accepted_as_images(): void
    {
        $p = app(ImageProcessor::class);
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>',
            'GIF89a<script>alert(1)</script>',
            '<?php echo 1; ?>',
        ] as $bytes) {
            try {
                $p->process($bytes);
                $this->fail('accepted non-image bytes');
            } catch (ImageValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_advertisement_datetimes_with_offsets_keep_their_instant_and_urls_are_scheme_checked(): void
    {
        $admin = User::factory()->admin()->create();
        $ad = AdvertisementFactory::new()->create();
        $start = '2026-12-01T00:00:00Z';
        $end = '2026-12-31T00:00:00+05:30';

        $this->as($admin)->patchJson("/api/advertisements/{$ad->id}/", ['start_at' => $start, 'end_at' => $end])->assertOk();
        $ad->refresh();
        $this->assertSame(strtotime($start), $ad->start_at->getTimestamp());
        $this->assertSame(strtotime($end), $ad->end_at->getTimestamp());

        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'ftp://example.com/x', '//evil.example', 'http://exa mple.com'] as $bad) {
            $this->as($admin)->patchJson("/api/advertisements/{$ad->id}/", ['target_url' => $bad])->assertStatus(400)->assertJsonStructure(['target_url']);
        }
        $this->assertSame('https://example.com/landing', $ad->fresh()->target_url);
    }

    public function test_a_user_cannot_verify_or_read_another_users_payment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sub = SubscriptionFactory::new()->create(['user_id' => $owner->id]);
        $pay = PaymentFactory::new()->forSubscription($sub)->create(['razorpay_order_id' => 'order_IDOR1']);
        $secret = 'idor-secret';
        config(['portal.razorpay.key_id' => 'rzp_test_x', 'portal.razorpay.key_secret' => $secret]);
        $sig = hash_hmac('sha256', 'order_IDOR1|pay_1', $secret);

        $this->as($other)->postJson('/api/subscriptions/verify/', [
            'razorpay_order_id' => 'order_IDOR1', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig,
        ])->assertStatus(400);
        $this->assertSame('PENDING', $sub->fresh()->status);
        $this->assertSame('CREATED', $pay->fresh()->status);
        $this->assertSame('null', $this->as($other)->getJson('/api/subscriptions/me/')->assertOk()->getContent());
        $this->as($other)->getJson('/api/subscriptions/admin/payments/')->assertStatus(403);
        $this->assertSame(0, Subscription::query()->where('user_id', $other->id)->count());
    }

    public function test_notifications_and_reviews_are_not_readable_across_users(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $n = Notification::query()->create(['recipient_id' => $a->id, 'notification_type' => 'ARTICLE_PUBLISHED', 'message' => 'private', 'is_read' => false]);
        $this->as($b)->getJson("/api/notifications/{$n->id}/")->assertStatus(404);
        $this->as($b)->postJson("/api/notifications/{$n->id}/mark-read/")->assertStatus(404);
        $this->assertFalse($n->fresh()->is_read);
        $this->as($b)->getJson('/api/notifications/')->assertOk()->assertJsonPath('count', 0);

        $author = User::factory()->reporter()->create();
        $draft = Article::factory()->status(ArticleStatus::DRAFT)->create(['author_id' => $author->id]);
        $this->as($b)->getJson("/api/articles/{$draft->slug}/review-history/")->assertStatus(404);
        $stranger = User::factory()->reporter()->create();
        $this->as($stranger)->getJson("/api/articles/{$draft->slug}/review-history/")->assertStatus(404);
        $this->as($stranger)->postJson("/api/articles/{$draft->slug}/ai-check/")->assertStatus(404);
        $this->as($stranger)->patchJson("/api/articles/{$draft->slug}/", ['title' => 'pwn'])->assertStatus(404);
        $this->as($stranger)->deleteJson("/api/articles/{$draft->slug}/")->assertStatus(404);
    }

    public function test_html_sanitizer_blocks_uri_and_attribute_vectors(): void
    {
        $bad = [
            '<a href="jAvAsCrIpT:alert(1)">x</a>' => ['javascript', 'alert'],
            '<a href="&#106;avascript:alert(1)">x</a>' => ['javascript'],
            '<img src="data:image/svg+xml;base64,PHN2Zz4=" onerror="alert(1)">' => ['data:', 'onerror'],
            '<img src="https://a.test/x.png" onload="alert(1)" srcset="javascript:alert(1) 1x">' => ['onload', 'srcset', 'javascript'],
            '<p style="background:url(javascript:alert(1))" onclick="x()">hi</p>' => ['style', 'onclick'],
            '<svg><script>alert(1)</script></svg>' => ['script', 'alert'],
            '<iframe src="https://evil.test"></iframe>' => ['iframe'],
            '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>' => ['onerror', 'style'],
            '<a href="vbscript:msgbox(1)">v</a>' => ['vbscript'],
            '<form action="https://evil.test"><input name=a></form>' => ['form', 'input'],
        ];
        foreach ($bad as $html => $forbidden) {
            $out = strtolower(HtmlSanitizer::clean($html));
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $out, "$needle survived in: $html => $out");
            }
        }
        $this->assertSame('<a href="https://ok.test" target="_blank" rel="noreferrer noopener">ok</a>', HtmlSanitizer::clean('<a href="https://ok.test" target="_blank" onclick="x()">ok</a>'));
    }

    public function test_otp_requests_to_one_phone_number_are_capped_across_accounts(): void
    {
        Http::fake();
        config(['portal.wati.endpoint' => 'https://wati.test', 'portal.wati.token' => 't', 'portal.wati.templates.otp' => 'otp_tpl']);
        $sent = 0;
        foreach (range(1, 8) as $i) {
            $u = User::factory()->create();
            $this->as($u)->postJson('/api/subscriptions/otp/request/', ['phone' => '9876501234'])->assertOk();
        }
        foreach (Http::recorded() as [$req]) {
            $sent += str_contains($req->url(), 'sendTemplateMessage') ? 1 : 0;
        }
        $this->assertSame(5, $sent);
    }

    public function test_image_uploads_are_rate_limited_per_user(): void
    {
        config(['portal.throttle.upload.rate' => '2/minute']);
        $author = User::factory()->reporter()->create();
        $a = Article::factory()->create(['author_id' => $author->id]);
        $this->as($author);
        $this->postJson("/api/articles/{$a->slug}/images/", [])->assertStatus(400);
        $this->postJson("/api/articles/{$a->slug}/images/", [])->assertStatus(400);
        $this->postJson("/api/articles/{$a->slug}/images/", [])->assertStatus(429);
        // another user has their own budget
        $this->as(User::factory()->admin()->create())->postJson("/api/articles/{$a->slug}/images/", [])->assertStatus(400);
    }

    public function test_newsroom_email_addresses_are_masked_for_public_callers(): void
    {
        $author = User::factory()->reporter()->create(['email' => 'jane.doe@newsroom.example', 'first_name' => '', 'last_name' => '']);
        $named = User::factory()->reporter()->create(['email' => 'bob@newsroom.example', 'first_name' => 'Bob', 'last_name' => 'Ray']);
        $a = Article::factory()->published()->create(['author_id' => $author->id, 'assigned_reporter_id' => $named->id]);
        ArticleImageFactory::new()->forArticle($a)->create(['uploaded_by_id' => $author->id]);

        foreach ([null, User::factory()->create(), $this->subscriberWithPlan()] as $caller) {
            $res = $this->as($caller)->getJson("/api/articles/{$a->slug}/");
            $this->assertStringNotContainsString('newsroom.example', str_replace(['j***@newsroom.example', 'b***@newsroom.example'], '', $res->getContent()));
            $res->assertJsonPath('author.email', 'j***@newsroom.example')->assertJsonPath('author.full_name', 'j***@newsroom.example')
                ->assertJsonPath('assigned_reporter.full_name', 'Bob Ray');
            $img = $this->as($caller)->getJson("/api/articles/{$a->slug}/images/")->assertOk();
            $this->assertStringNotContainsString('jane.doe', $img->getContent());
        }
        foreach ([User::factory()->reporter()->create(), User::factory()->admin()->create()] as $staff) {
            $this->as($staff)->getJson("/api/articles/{$a->slug}/")->assertJsonPath('author.email', 'jane.doe@newsroom.example');
            $this->assertStringContainsString('jane.doe@newsroom.example', $this->as($staff)->getJson("/api/articles/{$a->slug}/images/")->getContent());
        }
    }
}
