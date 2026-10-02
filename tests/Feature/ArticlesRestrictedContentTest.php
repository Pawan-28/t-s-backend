<?php

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Models\Article;
use App\Models\ReporterCategoryAssignment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

/** SUBSCRIBER_ONLY / RESTRICTED bodies (content + FAQs) must never be serialised for a non-entitled caller. */
class ArticlesRestrictedContentTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    private const BODY = 'ZXQSECRETBODYMARKER';

    private const FAQ = 'ZXQSECRETFAQANSWER';

    /** @return array<string, User|null> */
    private function callers(): array
    {
        return [
            'guest' => null,
            'plain user' => User::factory()->create(),
            'subscriber role without subscription' => User::factory()->subscriber()->create(),
            'expired subscriber (date passed)' => $this->subscriberWithPlan(Subscription::ACTIVE, now()->subDay()),
            'expired subscriber (status EXPIRED)' => $this->subscriberWithPlan(Subscription::EXPIRED, now()->addDays(3)),
            'pending subscriber' => $this->subscriberWithPlan(Subscription::PENDING),
        ];
    }

    private function locked(AccessLevel $level, array $extra = []): Article
    {
        $t = $this->tree();

        return Article::factory()->published()->access($level)->create($extra + [
            'subcategory_id' => $t['subcategory']->id,
            'title' => 'Locked story headline',
            'excerpt' => 'Public teaser only.',
            'content' => '<p>'.self::BODY.' full text</p>',
            'faqs' => [['question' => 'Why?', 'answer' => self::FAQ]],
        ]);
    }

    private function assertNoLeak($response): void
    {
        $raw = $response->getContent();
        $this->assertStringNotContainsString(self::BODY, $raw);
        $this->assertStringNotContainsString(self::FAQ, $raw);
    }

    private function assertLockedItem(array $item): void
    {
        $this->assertNull($item['content']);
        $this->assertSame([], $item['faqs']);
        $this->assertTrue($item['is_locked']);
        $this->assertSame('Public teaser only.', $item['excerpt'], 'teaser metadata stays visible');
    }

    public static function levels(): array
    {
        return [[AccessLevel::SUBSCRIBER_ONLY], [AccessLevel::RESTRICTED]];
    }

    #[DataProvider('levels')]
    public function test_detail_never_leaks_the_body_to_non_entitled_callers(AccessLevel $level): void
    {
        $a = $this->locked($level);
        foreach ($this->callers() as $label => $user) {
            $r = $this->as($user)->getJson("/api/articles/{$a->slug}/")->assertOk();
            $this->assertNoLeak($r);
            $this->assertLockedItem($r->json());
            $this->assertSame('Locked story headline', $r->json('title'), $label);
        }
    }

    #[DataProvider('levels')]
    public function test_list_never_leaks_the_body(AccessLevel $level): void
    {
        $this->locked($level);
        foreach ($this->callers() as $user) {
            $r = $this->as($user)->getJson('/api/articles/')->assertOk();
            $this->assertNoLeak($r);
            $this->assertLockedItem($r->json('results.0'));
        }
        $this->assertNoLeak($this->as(null)->getJson('/api/articles/?search='.self::BODY)->assertOk());
    }

    #[DataProvider('levels')]
    public function test_author_without_subscription_gets_no_body_in_mine_detail_and_update_responses(AccessLevel $level): void
    {
        $author = User::factory()->reporter()->create();
        $t = $this->tree();
        ReporterCategoryAssignment::query()->create(['reporter_id' => $author->id, 'category_id' => $t['category']->id]);
        $a = $this->locked($level, ['author_id' => $author->id, 'subcategory_id' => $t['subcategory']->id, 'status' => 'DRAFT', 'published_at' => null]);

        $mine = $this->as($author)->getJson('/api/articles/mine/')->assertOk();
        $this->assertNoLeak($mine);
        $this->assertLockedItem($mine->json('results.0'));

        $detail = $this->as($author)->getJson("/api/articles/{$a->slug}/")->assertOk();
        $this->assertNoLeak($detail);
        $this->assertLockedItem($detail->json());

        $patch = $this->as($author)->patchJson("/api/articles/{$a->slug}/", ['excerpt' => 'Public teaser only.'])->assertOk();
        $this->assertNoLeak($patch);
        $this->assertLockedItem($patch->json());
        $this->assertStringContainsString(self::BODY, $a->fresh()->content, 'the body itself is still stored untouched');
    }

    #[DataProvider('levels')]
    public function test_assigned_reporter_without_subscription_gets_no_body(AccessLevel $level): void
    {
        $assigned = User::factory()->reporter()->create();
        $this->locked($level, ['assigned_reporter_id' => $assigned->id, 'status' => 'UNDER_REVIEW', 'published_at' => null]);
        $r = $this->as($assigned)->getJson('/api/articles/assigned/')->assertOk();
        $this->assertNoLeak($r);
        $this->assertLockedItem($r->json('results.0'));
    }

    #[DataProvider('levels')]
    public function test_related_and_search_never_leak_the_body(AccessLevel $level): void
    {
        $source = Article::factory()->published()->create(['subcategory_id' => ($t = $this->tree())['subcategory']->id, 'title' => 'Source piece']);
        $locked = $this->locked($level, ['subcategory_id' => $t['subcategory']->id]);
        foreach ($this->callers() as $user) {
            $rel = $this->as($user)->getJson("/api/articles/{$source->slug}/related/")->assertOk();
            $this->assertNoLeak($rel);
            $this->assertCount(1, $rel->json());
            $this->assertLockedItem($rel->json('0'));

            $s = $this->as($user)->getJson('/api/search/?q='.urlencode('locked story'))->assertOk();
            $this->assertNoLeak($s);
            $this->assertSame($locked->slug, $s->json('results.0.slug'));
            $this->assertLockedItem($s->json('results.0'));
        }
    }

    #[DataProvider('levels')]
    public function test_entitled_subscriber_and_admin_receive_the_body(AccessLevel $level): void
    {
        $a = $this->locked($level);
        $entitled = [$this->subscriberWithPlan(), User::factory()->admin()->create()];
        foreach ($entitled as $user) {
            $r = $this->as($user)->getJson("/api/articles/{$a->slug}/")->assertOk();
            $this->assertStringContainsString(self::BODY, $r->json('content'));
            $this->assertSame(self::FAQ, $r->json('faqs.0.answer'));
            $this->assertFalse($r->json('is_locked'));
            $this->assertStringContainsString(self::BODY, $this->as($user)->getJson('/api/articles/')->json('results.0.content'));
            $this->assertStringContainsString(self::BODY, $this->as($user)->getJson('/api/search/?q=locked')->json('results.0.content'));
        }
    }

    public function test_public_article_is_never_locked_for_anyone(): void
    {
        $t = $this->tree();
        $a = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id, 'content' => '<p>'.self::BODY.'</p>', 'faqs' => [['question' => 'q', 'answer' => 'a']]]);
        $r = $this->as(null)->getJson("/api/articles/{$a->slug}/")->assertOk();
        $this->assertFalse($r->json('is_locked'));
        $this->assertStringContainsString(self::BODY, $r->json('content'));
        $this->assertCount(1, $r->json('faqs'));
    }

    public function test_inactive_subscriber_account_is_treated_as_guest_and_refresh_token_never_unlocks(): void
    {
        $a = $this->locked(AccessLevel::SUBSCRIBER_ONLY);
        $sub = $this->subscriberWithPlan();
        $refresh = $sub->createToken('refresh', ['refresh'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $r = $this->withToken($refresh)->getJson("/api/articles/{$a->slug}/")->assertOk();
        $this->assertNoLeak($r);
        $this->assertTrue($r->json('is_locked'));
    }
}
