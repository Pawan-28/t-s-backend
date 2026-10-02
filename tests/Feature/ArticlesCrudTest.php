<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Events\ArticleViewed;
use App\Models\Article;
use App\Models\ReporterCategoryAssignment;
use App\Models\Subcategory;
use App\Models\Tag;
use App\Models\User;
use App\Services\Workflow\ArticleWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ArticleTestHelpers;
use Tests\TestCase;

class ArticlesCrudTest extends TestCase
{
    use ArticleTestHelpers, RefreshDatabase;

    private function payload(Subcategory $sub, array $over = []): array
    {
        return array_merge([
            'title' => 'Markets rally on budget day',
            'content' => '<p>Body text of the story.</p>',
            'subcategory_slug' => $sub->slug,
        ], $over);
    }

    private function reporterFor(Subcategory $sub): User
    {
        $r = User::factory()->reporter()->create();
        ReporterCategoryAssignment::query()->create(['reporter_id' => $r->id, 'category_id' => $sub->category_id]);

        return $r;
    }

    // ---- create -----------------------------------------------------------

    public function test_reporter_creates_draft_with_derived_taxonomy_and_slug(): void
    {
        $t = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $r = $this->as($reporter)->postJson('/api/articles/', $this->payload($t['subcategory'], ['excerpt' => 'Short.', 'location_name' => 'Mumbai, India']))->assertCreated();
        $r->assertJsonPath('slug', 'markets-rally-on-budget-day')
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('access_level', 'PUBLIC')
            ->assertJsonPath('author.id', $reporter->id)
            ->assertJsonPath('subcategory.id', $t['subcategory']->id)
            ->assertJsonPath('category.id', $t['category']->id)
            ->assertJsonPath('industry.id', $t['industry']->id)
            ->assertJsonPath('location_name', 'Mumbai, India')
            ->assertJsonPath('is_locked', false)
            ->assertJsonPath('tags', [])
            ->assertJsonPath('faqs', []);
        $this->assertNull($r->json('published_at'));
        $this->assertArrayNotHasKey('subcategory_slug', $r->json());
        $this->assertDatabaseHas('articles', ['slug' => 'markets-rally-on-budget-day', 'category_id' => $t['category']->id, 'author_id' => $reporter->id]);
    }

    public function test_slug_is_unique_with_numeric_suffix_and_explicit_slug_must_be_unique(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory']))->assertCreated()->assertJsonPath('slug', 'markets-rally-on-budget-day');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory']))->assertCreated()->assertJsonPath('slug', 'markets-rally-on-budget-day-2');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory']))->assertCreated()->assertJsonPath('slug', 'markets-rally-on-budget-day-3');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['slug' => 'Markets-Rally-On-Budget-Day']))
            ->assertStatus(400)->assertExactJson(['slug' => ['An article with this slug already exists.']]);
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['slug' => 'custom-one']))->assertCreated()->assertJsonPath('slug', 'custom-one');
    }

    public function test_titles_that_would_collide_with_static_routes_get_a_suffix_and_reserved_slugs_are_rejected(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['title' => 'Mine']))->assertCreated()->assertJsonPath('slug', 'mine-2');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['slug' => 'assigned']))->assertStatus(400)->assertJsonPath('slug.0', 'This slug is reserved.');
    }

    public function test_create_permissions_by_role(): void
    {
        $t = $this->tree();
        $p = $this->payload($t['subcategory']);
        $this->as(null)->postJson('/api/articles/', $p)->assertStatus(401);
        foreach ([User::factory()->create(), User::factory()->subscriber()->create()] as $u) {
            $this->as($u)->postJson('/api/articles/', $p)->assertStatus(403)->assertJsonPath('detail', 'Only reporters and administrators can create or modify articles.');
        }
        $this->as(User::factory()->admin()->create())->postJson('/api/articles/', $p)->assertCreated();
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_reporter_needs_a_category_assignment_for_the_subcategory(): void
    {
        $t = $this->tree();
        $other = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $msg = 'You are not assigned to the category this subcategory belongs to. Ask an administrator to assign you to it first.';
        $this->as($reporter)->postJson('/api/articles/', $this->payload($other['subcategory']))->assertStatus(400)->assertExactJson(['subcategory_slug' => [$msg]]);
        $this->as(User::factory()->reporter()->create())->postJson('/api/articles/', $this->payload($t['subcategory']))->assertStatus(400)->assertJsonPath('subcategory_slug.0', $msg);
        $this->as($reporter)->postJson('/api/articles/', $this->payload($t['subcategory']))->assertCreated();
        // admin bypasses the assignment rule
        $this->as(User::factory()->admin()->create())->postJson('/api/articles/', $this->payload($other['subcategory']))->assertCreated();
    }

    public function test_assignment_is_category_level_so_any_subcategory_under_it_works(): void
    {
        $t = $this->tree();
        $sibling = Subcategory::factory()->create(['category_id' => $t['category']->id]);
        $reporter = $this->reporterFor($t['subcategory']);
        $this->as($reporter)->postJson('/api/articles/', $this->payload($sibling))->assertCreated();
    }

    public function test_required_fields_and_messages(): void
    {
        $admin = User::factory()->admin()->create();
        $r = $this->as($admin)->postJson('/api/articles/', [])->assertStatus(400);
        $this->assertSame(['This field is required.'], $r->json('title'));
        $this->assertSame(['This field is required.'], $r->json('content'));
        $this->assertSame(['This field is required.'], $r->json('subcategory_slug'));
        $t = $this->tree();
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['title' => '   ']))->assertStatus(400)->assertExactJson(['title' => ['This field may not be blank.']]);
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['title' => str_repeat('x', 256)]))->assertStatus(400)->assertJsonPath('title.0', 'Ensure this field has no more than 255 characters.');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['access_level' => 'SECRET']))->assertStatus(400)->assertJsonPath('access_level.0', '"SECRET" is not a valid choice.');
    }

    public function test_subcategory_must_be_active_with_active_category_and_industry_and_exist(): void
    {
        $admin = User::factory()->admin()->create();
        $inactiveSub = $this->tree(subcategory: ['is_active' => false])['subcategory'];
        $inactiveCat = $this->tree(category: ['is_active' => false])['subcategory'];
        $inactiveInd = $this->tree(industry: ['is_active' => false])['subcategory'];
        foreach ([$inactiveSub, $inactiveCat, $inactiveInd] as $sub) {
            $this->as($admin)->postJson('/api/articles/', $this->payload($sub))->assertStatus(400)->assertJsonPath('subcategory_slug.0', "Object with slug={$sub->slug} does not exist.");
        }
        $this->as($admin)->postJson('/api/articles/', $this->payload($inactiveSub, ['subcategory_slug' => 'ghost']))->assertStatus(400)->assertJsonPath('subcategory_slug.0', 'Object with slug=ghost does not exist.');
    }

    public function test_ambiguous_subcategory_slug_across_categories_is_a_400_not_a_500(): void
    {
        $a = $this->tree(subcategory: ['slug' => 'news']);
        $this->tree(subcategory: ['slug' => 'news']);
        $r = $this->as(User::factory()->admin()->create())->postJson('/api/articles/', $this->payload($a['subcategory']))->assertStatus(400);
        $this->assertStringContainsString('More than one active subcategory has the slug "news"', $r->json('subcategory_slug.0'));
    }

    public function test_tag_slugs_are_synced_and_unknown_slug_is_rejected(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $a = Tag::factory()->create(['slug' => 'a-tag']);
        $b = Tag::factory()->create(['slug' => 'b-tag']);
        $r = $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['tag_slugs' => ['a-tag', 'b-tag']]))->assertCreated();
        $this->assertEqualsCanonicalizing(['a-tag', 'b-tag'], array_column($r->json('tags'), 'slug'));
        $slug = $r->json('slug');
        $this->as($admin)->patchJson("/api/articles/{$slug}/", ['tag_slugs' => ['b-tag']])->assertOk()->assertJsonCount(1, 'tags')->assertJsonPath('tags.0.slug', 'b-tag');
        $this->as($admin)->patchJson("/api/articles/{$slug}/", ['title' => 'Renamed'])->assertOk()->assertJsonCount(1, 'tags');
        $this->as($admin)->patchJson("/api/articles/{$slug}/", ['tag_slugs' => []])->assertOk()->assertJsonCount(0, 'tags');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['tag_slugs' => ['nope']]))->assertStatus(400)->assertExactJson(['tag_slugs' => ['Object with slug=nope does not exist.']]);
        $this->assertNotNull($a->id);
    }

    // ---- sanitization -----------------------------------------------------

    public function test_content_is_sanitized_scripts_and_event_handlers_stripped(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $dirty = '<p onclick="steal()">Hi <script>alert(1)</script><strong>there</strong></p>'
            .'<img src="https://x.test/a.png" onerror="alert(2)" alt="pic">'
            .'<a href="javascript:alert(3)">bad</a><a href="https://ok.test/x" onmouseover="z()">good</a>'
            .'<iframe src="https://evil.test"></iframe><style>p{color:red}</style><h1>h1</h1><h2>h2</h2>';
        $r = $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['content' => $dirty]))->assertCreated();
        $content = $r->json('content');
        foreach (['<script', 'alert', 'onclick', 'onerror', 'onmouseover', 'javascript:', '<iframe', '<style', 'color:red', '<h1'] as $bad) {
            $this->assertStringNotContainsString($bad, $content, "still contains {$bad}");
        }
        foreach (['<strong>there</strong>', '<img src="https://x.test/a.png"', 'href="https://ok.test/x"', '<h2>h2</h2>'] as $good) {
            $this->assertStringContainsString($good, $content);
        }
        $this->assertStringNotContainsString('alert', Article::first()->content);
    }

    public function test_only_safe_uri_schemes_survive_and_content_blank_after_sanitising_is_rejected(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $c = $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], [
            'content' => '<p><a href="data:text/html;base64,QQ==">d</a> <a href="vbscript:x">v</a> <a href="mailto:a@b.co">m</a> <img src="data:image/png;base64,AA==" alt="i"></p>',
        ]))->assertCreated()->json('content');
        $this->assertStringNotContainsString('data:', $c);
        $this->assertStringNotContainsString('vbscript', $c);
        $this->assertStringContainsString('mailto:a@b.co', $c);
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['content' => '<script>alert(1)</script>']))
            ->assertStatus(400)->assertExactJson(['content' => ['Content cannot be blank after sanitization.']]);
    }

    public function test_location_name_is_plain_text(): void
    {
        $t = $this->tree();
        $r = $this->as(User::factory()->admin()->create())->postJson('/api/articles/', $this->payload($t['subcategory'], ['location_name' => '<b>Mumbai</b>  &amp; <script>x</script>Thane']))->assertCreated();
        $this->assertStringNotContainsString('<', $r->json('location_name'));
        $this->assertStringContainsString('Mumbai', $r->json('location_name'));
    }

    // ---- FAQs -------------------------------------------------------------

    public function test_faqs_valid_list_is_stored_plain_text_and_empty_rows_are_dropped(): void
    {
        $t = $this->tree();
        $r = $this->as(User::factory()->admin()->create())->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => [
            ['question' => 'What is <b>this</b>?', 'answer' => 'An   answer.'],
            ['question' => '', 'answer' => ''],
        ]]))->assertCreated();
        $this->assertEquals([['question' => 'What is this?', 'answer' => 'An answer.']], $r->json('faqs'));
    }

    public function test_faq_validation_errors(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => [['question' => 'Only q', 'answer' => '']]]))
            ->assertStatus(400)->assertExactJson(['faqs' => [['non_field_errors' => ['Each FAQ needs both a question and an answer.']]]]);
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => [['question' => str_repeat('q', 301), 'answer' => 'a']]]))
            ->assertStatus(400)->assertJsonPath('faqs.0.question.0', 'Ensure this field has no more than 300 characters.');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => [['question' => 'q', 'answer' => str_repeat('a', 2001)]]]))
            ->assertStatus(400)->assertJsonPath('faqs.0.answer.0', 'Ensure this field has no more than 2000 characters.');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => [['question' => 'q']]]))
            ->assertStatus(400)->assertJsonPath('faqs.0.answer.0', 'This field is required.');
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => 'nope']))->assertStatus(400)->assertJsonStructure(['faqs']);
        $eleven = array_map(fn ($i) => ['question' => "q{$i}", 'answer' => "a{$i}"], range(1, 11));
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => $eleven]))->assertStatus(400)->assertExactJson(['faqs' => ['An article can have at most 10 FAQs.']]);
        $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['faqs' => array_slice($eleven, 0, 10)]))->assertCreated()->assertJsonCount(10, 'faqs');
    }

    // ---- update / author immutability -------------------------------------

    public function test_author_is_immutable_even_for_admin_and_ignored_read_only_fields(): void
    {
        $t = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $admin = User::factory()->admin()->create();
        $other = User::factory()->reporter()->create();
        $article = Article::factory()->create(['author_id' => $reporter->id, 'subcategory_id' => $t['subcategory']->id]);

        $r = $this->as($admin)->patchJson("/api/articles/{$article->slug}/", [
            'title' => 'New title', 'author' => $other->id, 'author_id' => $other->id, 'assigned_reporter' => $other->id,
            'published_at' => '2020-01-01T00:00:00Z', 'rejection_reason' => 'hax', 'scheduled_publish_at' => '2030-01-01T00:00:00Z',
        ])->assertOk();
        $r->assertJsonPath('author.id', $reporter->id)->assertJsonPath('title', 'New title');
        $fresh = $article->fresh();
        $this->assertSame($reporter->id, $fresh->author_id);
        $this->assertNull($fresh->assigned_reporter_id);
        $this->assertNull($fresh->published_at);
        $this->assertNull($fresh->scheduled_publish_at);
        $this->assertSame('', $fresh->rejection_reason);
        $this->assertSame($article->slug, $fresh->slug, 'renaming does not change the slug');
    }

    public function test_model_level_author_change_is_reverted(): void
    {
        $article = Article::factory()->create();
        $author = $article->author_id;
        $article->author_id = User::factory()->create()->id;
        $article->save();
        $this->assertSame($author, $article->fresh()->author_id);
    }

    public function test_subcategory_change_rederives_category_and_industry(): void
    {
        $a = $this->tree();
        $b = $this->tree();
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->create(['subcategory_id' => $a['subcategory']->id]);
        $r = $this->as($admin)->patchJson("/api/articles/{$article->slug}/", ['subcategory_slug' => $b['subcategory']->slug])->assertOk();
        $r->assertJsonPath('subcategory.id', $b['subcategory']->id)->assertJsonPath('category.id', $b['category']->id)->assertJsonPath('industry.id', $b['industry']->id);
        $this->assertSame($b['category']->id, $article->fresh()->category_id);
    }

    public function test_put_requires_full_payload_but_patch_is_partial(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->create(['subcategory_id' => $t['subcategory']->id]);
        $this->as($admin)->putJson("/api/articles/{$article->slug}/", ['title' => 'Only title'])->assertStatus(400)->assertJsonStructure(['content', 'subcategory_slug']);
        $this->as($admin)->putJson("/api/articles/{$article->slug}/", $this->payload($t['subcategory'], ['title' => 'Full']))->assertOk()->assertJsonPath('title', 'Full');
        $this->as($admin)->patchJson("/api/articles/{$article->slug}/", ['excerpt' => 'x'])->assertOk()->assertJsonPath('excerpt', 'x')->assertJsonPath('title', 'Full');
        $this->as($admin)->patchJson("/api/articles/{$article->slug}/", ['slug' => ''])->assertOk()->assertJsonPath('slug', 'full');
    }

    public function test_reporter_edit_rules_by_status_and_ownership(): void
    {
        $t = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $stranger = $this->reporterFor($t['subcategory']);
        $mk = fn (ArticleStatus $s, array $x = []) => Article::factory()->status($s)->create(['author_id' => $reporter->id, 'subcategory_id' => $t['subcategory']->id, 'published_at' => $s === ArticleStatus::PUBLISHED ? now() : null] + $x);

        $draft = $mk(ArticleStatus::DRAFT);
        $this->as($reporter)->patchJson("/api/articles/{$draft->slug}/", ['title' => 'ok'])->assertOk();
        $cr = $mk(ArticleStatus::CHANGES_REQUESTED);
        $this->as($reporter)->patchJson("/api/articles/{$cr->slug}/", ['title' => 'ok'])->assertOk();
        foreach ([ArticleStatus::SUBMITTED, ArticleStatus::UNDER_REVIEW, ArticleStatus::APPROVED, ArticleStatus::SCHEDULED, ArticleStatus::REJECTED, ArticleStatus::PUBLISHED] as $s) {
            $a = $mk($s);
            $this->as($reporter)->patchJson("/api/articles/{$a->slug}/", ['title' => 'no'])->assertStatus(403);
        }
        // not the author, article not visible (draft of someone else) => 404, published of someone else => 403
        $this->as($stranger)->patchJson("/api/articles/{$draft->slug}/", ['title' => 'x'])->assertStatus(404);
        $pub = $mk(ArticleStatus::PUBLISHED);
        $this->as($stranger)->patchJson("/api/articles/{$pub->slug}/", ['title' => 'x'])->assertStatus(403);
        // the assigned reporter may edit while UNDER_REVIEW
        $ur = $mk(ArticleStatus::UNDER_REVIEW, ['assigned_reporter_id' => $stranger->id]);
        $this->as($stranger)->patchJson("/api/articles/{$ur->slug}/", ['title' => 'assigned edit'])->assertOk();
        // plain users / guests
        $this->as(User::factory()->create())->patchJson("/api/articles/{$pub->slug}/", ['title' => 'x'])->assertStatus(403);
        $this->as(null)->patchJson("/api/articles/{$pub->slug}/", ['title' => 'x'])->assertStatus(401);
    }

    public function test_reporter_moving_an_article_to_an_unassigned_category_is_rejected(): void
    {
        $t = $this->tree();
        $other = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $article = Article::factory()->create(['author_id' => $reporter->id, 'subcategory_id' => $t['subcategory']->id]);
        $this->as($reporter)->patchJson("/api/articles/{$article->slug}/", ['subcategory_slug' => $other['subcategory']->slug])->assertStatus(400)->assertJsonStructure(['subcategory_slug']);
        $this->assertSame($t['subcategory']->id, $article->fresh()->subcategory_id);
    }

    public function test_reporter_may_set_access_level_but_never_status(): void
    {
        $t = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $article = Article::factory()->create(['author_id' => $reporter->id, 'subcategory_id' => $t['subcategory']->id]);
        $this->as($reporter)->patchJson("/api/articles/{$article->slug}/", ['access_level' => 'SUBSCRIBER_ONLY'])->assertOk()->assertJsonPath('access_level', 'SUBSCRIBER_ONLY');
        // even an unchanged status value is refused for a non-admin
        $r = $this->as($reporter)->patchJson("/api/articles/{$article->slug}/", ['status' => 'DRAFT'])->assertStatus(400);
        $this->assertArrayHasKey('status', $r->json());
        $this->as($reporter)->postJson('/api/articles/', $this->payload($t['subcategory'], ['status' => 'PUBLISHED']))->assertStatus(400)->assertJsonStructure(['status']);
        $this->assertSame(ArticleStatus::DRAFT, $article->fresh()->status);
        $this->assertDatabaseCount('articles', 1);
    }

    public function test_admin_status_change_goes_through_the_workflow_service(): void
    {
        if (! class_exists(ArticleWorkflowService::class)) {
            $this->markTestSkipped('Workflow service not present.');
        }
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->create(['subcategory_id' => $t['subcategory']->id]);

        $spy = new class(app(ArticleWorkflowService::class))
        {
            public array $calls = [];

            public function __construct(public $real) {}
        };
        $mock = \Mockery::mock(ArticleWorkflowService::class);
        $mock->shouldReceive('changeStatus')->once()->andReturnUsing(function ($a, $u, $to) use (&$spy) {
            $spy->calls[] = [$a->id, $u->id, $to];
            $a->forceFill(['status' => $to, 'published_at' => now()])->save();

            return $a;
        });
        $this->app->instance(ArticleWorkflowService::class, $mock);

        $this->as($admin)->patchJson("/api/articles/{$article->slug}/", ['status' => 'PUBLISHED', 'title' => 'Go live'])->assertOk()->assertJsonPath('status', 'PUBLISHED')->assertJsonPath('title', 'Go live');
        $this->assertSame([[$article->id, $admin->id, ArticleStatus::PUBLISHED]], $spy->calls);
    }

    public function test_admin_invalid_transition_is_rejected_by_the_real_workflow_and_rolls_back_field_changes(): void
    {
        if (! class_exists(ArticleWorkflowService::class)) {
            $this->markTestSkipped('Workflow service not present.');
        }
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        $article = Article::factory()->status(ArticleStatus::PUBLISHED)->create(['subcategory_id' => $t['subcategory']->id, 'title' => 'Original', 'published_at' => now()]);
        $r = $this->as($admin)->patchJson("/api/articles/{$article->slug}/", ['status' => 'DRAFT', 'title' => 'Changed'])->assertStatus(400);
        $this->assertArrayHasKey('status', $r->json());
        $this->assertSame('Original', $article->fresh()->title);
        // same status is a harmless no-op
        $this->as($admin)->patchJson("/api/articles/{$article->slug}/", ['status' => 'PUBLISHED', 'title' => 'Edited'])->assertOk()->assertJsonPath('title', 'Edited');
    }

    // ---- delete -----------------------------------------------------------

    public function test_delete_permissions(): void
    {
        $t = $this->tree();
        $reporter = $this->reporterFor($t['subcategory']);
        $draft = Article::factory()->create(['author_id' => $reporter->id, 'subcategory_id' => $t['subcategory']->id]);
        $pub = Article::factory()->published()->create(['author_id' => $reporter->id, 'subcategory_id' => $t['subcategory']->id]);
        $this->as(null)->deleteJson("/api/articles/{$draft->slug}/")->assertStatus(401);
        $this->as(User::factory()->create())->deleteJson("/api/articles/{$pub->slug}/")->assertStatus(403);
        $this->as($reporter)->deleteJson("/api/articles/{$pub->slug}/")->assertStatus(403);
        $this->as($reporter)->deleteJson("/api/articles/{$draft->slug}/")->assertNoContent();
        $this->assertDatabaseMissing('articles', ['id' => $draft->id]);
        $this->as(User::factory()->admin()->create())->deleteJson("/api/articles/{$pub->slug}/")->assertNoContent();
        $this->as(User::factory()->admin()->create())->deleteJson('/api/articles/ghost/')->assertStatus(404);
    }

    // ---- detail / view event ---------------------------------------------

    public function test_public_detail_only_for_published_and_dispatches_view_event(): void
    {
        $t = $this->tree();
        $pub = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id]);
        $draft = Article::factory()->create(['subcategory_id' => $t['subcategory']->id]);
        Event::fake([ArticleViewed::class]);

        $this->as(null)->getJson("/api/articles/{$pub->slug}/")->assertOk()->assertJsonPath('slug', $pub->slug)->assertJsonPath('title', $pub->title);
        $this->as(null)->getJson("/api/articles/{$pub->slug}")->assertOk();
        Event::assertDispatchedTimes(ArticleViewed::class, 2);
        Event::assertDispatched(ArticleViewed::class, fn ($e) => $e->article->is($pub) && preg_match('/^[0-9a-f]{40}$/', $e->viewerKey) === 1);

        $this->as(null)->getJson("/api/articles/{$draft->slug}/")->assertStatus(404)->assertExactJson(['detail' => 'Not found.']);
        $this->as(User::factory()->create())->getJson("/api/articles/{$draft->slug}/")->assertStatus(404);
        Event::assertDispatchedTimes(ArticleViewed::class, 2);
    }

    public function test_view_event_uses_user_key_for_authenticated_readers_and_never_fires_for_drafts_even_for_admin(): void
    {
        $t = $this->tree();
        $pub = Article::factory()->published()->create(['subcategory_id' => $t['subcategory']->id]);
        $draft = Article::factory()->create(['subcategory_id' => $t['subcategory']->id]);
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        Event::fake([ArticleViewed::class]);

        $this->as($user)->getJson("/api/articles/{$pub->slug}/")->assertOk();
        Event::assertDispatched(ArticleViewed::class, fn ($e) => $e->viewerKey === 'u:'.$user->id);
        $this->as($admin)->getJson("/api/articles/{$draft->slug}/")->assertOk()->assertJsonPath('status', 'DRAFT');
        Event::assertDispatchedTimes(ArticleViewed::class, 1);
    }

    public function test_a_failing_view_listener_does_not_break_the_detail_response(): void
    {
        $pub = Article::factory()->published()->create();
        Event::listen(ArticleViewed::class, fn () => throw new \RuntimeException('redis down'));
        $this->as(null)->getJson("/api/articles/{$pub->slug}/")->assertOk();
    }

    public function test_draft_visibility_by_role(): void
    {
        $t = $this->tree();
        $author = $this->reporterFor($t['subcategory']);
        $assigned = User::factory()->reporter()->create();
        $stranger = User::factory()->reporter()->create();
        $draft = Article::factory()->create(['author_id' => $author->id, 'assigned_reporter_id' => $assigned->id, 'subcategory_id' => $t['subcategory']->id]);
        $this->as($author)->getJson("/api/articles/{$draft->slug}/")->assertOk();
        $this->as($assigned)->getJson("/api/articles/{$draft->slug}/")->assertOk();
        $this->as(User::factory()->admin()->create())->getJson("/api/articles/{$draft->slug}/")->assertOk();
        $this->as($stranger)->getJson("/api/articles/{$draft->slug}/")->assertStatus(404);
        $this->as(User::factory()->subscriber()->create())->getJson("/api/articles/{$draft->slug}/")->assertStatus(404);
    }

    public function test_static_routes_are_not_swallowed_by_the_slug_route(): void
    {
        $reporter = User::factory()->reporter()->create();
        $this->as($reporter)->getJson('/api/articles/mine/')->assertOk()->assertJsonStructure(['count', 'next', 'previous', 'results']);
        $this->as($reporter)->getJson('/api/articles/assigned/')->assertOk()->assertJsonStructure(['count', 'results']);
    }

    // ---- indexing hooks ---------------------------------------------------

    public function test_search_index_is_maintained_on_create_update_and_tag_sync(): void
    {
        $t = $this->tree();
        $admin = User::factory()->admin()->create();
        Tag::factory()->create(['slug' => 'zebratag', 'name' => 'Zebratag']);
        $slug = $this->as($admin)->postJson('/api/articles/', $this->payload($t['subcategory'], ['title' => 'Quokka habitat', 'tag_slugs' => ['zebratag']]))->assertCreated()->json('slug');
        $row = fn () => \DB::table('article_search_index')->join('articles', 'articles.id', '=', 'article_search_index.article_id')->where('articles.slug', $slug)->first();
        $this->assertStringContainsString('Quokka', $row()->title);
        $this->assertStringContainsString('Zebratag', $row()->taxonomy, 'tag names are indexed');
        $this->as($admin)->patchJson("/api/articles/{$slug}/", ['title' => 'Wombat habitat', 'tag_slugs' => []])->assertOk();
        $this->assertStringContainsString('Wombat', $row()->title);
        $this->assertStringNotContainsString('Quokka', $row()->title);
        $this->assertStringNotContainsString('Zebratag', $row()->taxonomy);
    }
}
