<?php

namespace Tests\Feature\Workflow;

use App\Enums\ArticleStatus as S;
use App\Models\PublishingSchedule;
use App\Models\ReporterCategoryAssignment;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\WorkflowFixtures;
use Tests\TestCase;

/** /api/reporters/assignments/ (admin CRUD) and /api/reporters/schedules/ (admin list). */
class ReporterAdminApiTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflow();
        ReporterCategoryAssignment::query()->delete();
    }

    private function send(?User $u, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if ($u) {
            $this->actingAsUser($u);
        }

        return $this->json($method, $uri, $data);
    }

    public function test_assignments_are_admin_only(): void
    {
        foreach ([[null, 401], [$this->plainUser, 403], [$this->author, 403]] as [$u, $code]) {
            $this->send($u, 'GET', '/api/reporters/assignments/')->assertStatus($code);
            $this->send($u, 'POST', '/api/reporters/assignments/', ['reporter' => $this->author->id, 'category' => $this->category->id])->assertStatus($code);
            $this->send($u, 'GET', '/api/reporters/schedules/')->assertStatus($code);
        }
        $this->assertSame(0, ReporterCategoryAssignment::count());
    }

    public function test_create_returns_201_with_expanded_shape_and_records_assigned_by(): void
    {
        $r = $this->send($this->admin, 'POST', '/api/reporters/assignments/', ['reporter' => $this->author->id, 'category' => $this->category->id])->assertStatus(201);
        $r->assertJsonPath('reporter', $this->author->id)->assertJsonPath('category', $this->category->id)
            ->assertJsonPath('category_name', $this->category->name)->assertJsonPath('industry_name', $this->category->industry->name)
            ->assertJsonPath('assigned_by_email', $this->admin->email)
            ->assertJsonPath('reporter_detail', ['id' => $this->author->id, 'email' => $this->author->email, 'first_name' => $this->author->first_name,
                'last_name' => $this->author->last_name, 'full_name' => $this->author->full_name, 'role' => 'REPORTER']);
        $this->assertSame(['id', 'reporter', 'reporter_detail', 'category', 'category_name', 'industry_name', 'assigned_by_email', 'created_at'], array_keys($r->json()));
    }

    public function test_create_validation_errors_are_drf_shaped(): void
    {
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', [])->assertStatus(400)
            ->assertExactJson(['reporter' => ['This field is required.'], 'category' => ['This field is required.']]);
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', ['reporter' => 999999, 'category' => $this->category->id])->assertStatus(400)
            ->assertExactJson(['reporter' => ['Invalid pk "999999" - object does not exist.']]);
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', ['reporter' => 'abc', 'category' => null])->assertStatus(400)
            ->assertExactJson(['reporter' => ['Incorrect type. Expected pk value, received str.'], 'category' => ['This field may not be null.']]);
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', ['reporter' => $this->plainUser->id, 'category' => $this->category->id])->assertStatus(400)
            ->assertExactJson(['reporter' => ['Only a user with role=REPORTER can be assigned to a category.']]);
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', ['reporter' => $this->admin->id, 'category' => $this->category->id])->assertStatus(400);
        $this->assertSame(0, ReporterCategoryAssignment::count());
    }

    public function test_duplicate_assignment_is_refused(): void
    {
        $body = ['reporter' => $this->author->id, 'category' => $this->category->id];
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', $body)->assertStatus(201);
        $this->send($this->admin, 'POST', '/api/reporters/assignments/', $body)->assertStatus(400)->assertJsonStructure(['non_field_errors']);
        $this->assertSame(1, ReporterCategoryAssignment::count());
    }

    public function test_list_filters_search_order_and_pagination(): void
    {
        $catB = CategoryFactory::new()->create(['name' => 'Bravo desk']);
        $catA = CategoryFactory::new()->create(['name' => 'Alpha desk']);
        $r1 = User::factory()->reporter()->create(['email' => 'zed@example.com', 'first_name' => 'Zed']);
        $r2 = User::factory()->reporter()->create(['email' => 'amy@example.com', 'first_name' => 'Amy']);
        foreach ([[$r1, $catB], [$r2, $catB], [$r1, $catA]] as [$r, $c]) {
            ReporterCategoryAssignment::create(['reporter_id' => $r->id, 'category_id' => $c->id, 'assigned_by_id' => $this->admin->id]);
        }
        $rows = fn ($r) => array_map(fn ($x) => [$x['category_name'], $x['reporter_detail']['email']], $r->json('results'));

        $all = $this->send($this->admin, 'GET', '/api/reporters/assignments/')->assertOk();
        $this->assertSame(3, $all->json('count'));
        $this->assertSame([['Alpha desk', 'zed@example.com'], ['Bravo desk', 'amy@example.com'], ['Bravo desk', 'zed@example.com']], $rows($all));

        $this->assertSame(2, $this->send($this->admin, 'GET', "/api/reporters/assignments/?reporter={$r1->id}")->json('count'));
        $this->assertSame(2, $this->send($this->admin, 'GET', "/api/reporters/assignments/?category={$catB->id}")->json('count'));
        $this->assertSame(1, $this->send($this->admin, 'GET', "/api/reporters/assignments/?category={$catB->id}&reporter={$r2->id}")->json('count'));
        $this->assertSame(1, $this->send($this->admin, 'GET', '/api/reporters/assignments/?search=amy')->json('count'));
        $this->assertSame(1, $this->send($this->admin, 'GET', '/api/reporters/assignments/?search=alpha')->json('count'));
        $this->assertSame(2, $this->send($this->admin, 'GET', '/api/reporters/assignments/?search=zed')->json('count'));
        $this->send($this->admin, 'GET', '/api/reporters/assignments/?reporter=99999')->assertStatus(400)->assertJsonStructure(['reporter']);
    }

    public function test_retrieve_update_patch_delete(): void
    {
        $a = ReporterCategoryAssignment::create(['reporter_id' => $this->author->id, 'category_id' => $this->category->id, 'assigned_by_id' => $this->admin->id]);
        $cat2 = CategoryFactory::new()->create();

        $this->send($this->admin, 'GET', "/api/reporters/assignments/{$a->id}/")->assertOk()->assertJsonPath('id', $a->id);
        $this->send($this->admin, 'PATCH', "/api/reporters/assignments/{$a->id}/", ['category' => $cat2->id])->assertOk()
            ->assertJsonPath('category', $cat2->id)->assertJsonPath('reporter', $this->author->id)->assertJsonPath('assigned_by_email', $this->admin->email);
        $this->send($this->admin, 'PUT', "/api/reporters/assignments/{$a->id}/", ['reporter' => $this->assignee->id])->assertStatus(400)
            ->assertExactJson(['category' => ['This field is required.']]);
        $this->send($this->admin, 'PUT', "/api/reporters/assignments/{$a->id}/", ['reporter' => $this->assignee->id, 'category' => $cat2->id])->assertOk()
            ->assertJsonPath('reporter', $this->assignee->id);
        // patch to itself is not a duplicate; patch onto another existing pair is
        ReporterCategoryAssignment::create(['reporter_id' => $this->author->id, 'category_id' => $cat2->id]);
        $this->send($this->admin, 'PATCH', "/api/reporters/assignments/{$a->id}/", ['reporter' => $this->assignee->id])->assertOk();
        $this->send($this->admin, 'PATCH', "/api/reporters/assignments/{$a->id}/", ['reporter' => $this->author->id])->assertStatus(400)->assertJsonStructure(['non_field_errors']);

        $this->send($this->admin, 'DELETE', "/api/reporters/assignments/{$a->id}/")->assertStatus(204);
        $this->assertNull(ReporterCategoryAssignment::find($a->id));
        $this->send($this->admin, 'GET', "/api/reporters/assignments/{$a->id}/")->assertStatus(404)->assertJsonPath('detail', 'Not found.');
        $this->send($this->admin, 'DELETE', '/api/reporters/assignments/999999/')->assertStatus(404);
    }

    public function test_deleting_the_assignment_blocks_future_article_assignment(): void
    {
        $a = ReporterCategoryAssignment::create(['reporter_id' => $this->assignee->id, 'category_id' => $this->category->id]);
        $art = $this->article();
        $this->send($this->admin, 'DELETE', "/api/reporters/assignments/{$a->id}/")->assertStatus(204);
        $this->act($this->admin, $art->slug, 'assign-reporter', ['reporter_id' => $this->assignee->id])->assertStatus(400);
    }

    // -------------------------------------------------------------------------------- schedules list

    public function test_schedules_list_shape_filter_and_order(): void
    {
        $a1 = $this->article(S::SCHEDULED, ['title' => 'First scheduled']);
        $a2 = $this->article(S::PUBLISHED, ['title' => 'Second done', 'published_at' => now()]);
        $old = PublishingSchedule::create(['article_id' => $a2->id, 'scheduled_for' => now()->subDay(), 'scheduled_by_id' => $this->admin->id, 'status' => 'EXECUTED', 'executed_at' => now()]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $new = PublishingSchedule::create(['article_id' => $a1->id, 'scheduled_for' => now()->addDay(), 'scheduled_by_id' => $this->admin->id, 'status' => 'PENDING']);

        $r = $this->send($this->admin, 'GET', '/api/reporters/schedules/')->assertOk();
        $this->assertSame(2, $r->json('count'));
        $this->assertSame([$new->id, $old->id], array_column($r->json('results'), 'id'));
        $this->assertSame(['id', 'article_title', 'article_slug', 'article_status', 'scheduled_for', 'scheduled_by_email', 'status', 'executed_at', 'created_at', 'updated_at'], array_keys($r->json('results.0')));
        $this->assertSame('First scheduled', $r->json('results.0.article_title'));
        $this->assertSame('SCHEDULED', $r->json('results.0.article_status'));
        $this->assertSame($this->admin->email, $r->json('results.0.scheduled_by_email'));

        $this->assertSame([$new->id], array_column($this->send($this->admin, 'GET', '/api/reporters/schedules/?status=PENDING')->json('results'), 'id'));
        $this->assertSame([$old->id], array_column($this->send($this->admin, 'GET', '/api/reporters/schedules/?status=EXECUTED')->json('results'), 'id'));
        $this->send($this->admin, 'GET', '/api/reporters/schedules/?status=NOPE')->assertStatus(400)->assertJsonStructure(['status']);
    }
}
