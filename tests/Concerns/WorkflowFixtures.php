<?php

namespace Tests\Concerns;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\ReporterCategoryAssignment;
use App\Models\Subcategory;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Database\Factories\SubcategoryFactory;
use Illuminate\Testing\TestResponse;

/** Shared cast + article builder for the workflow / reporter / schedule feature tests. */
trait WorkflowFixtures
{
    protected User $admin;

    protected User $admin2;

    protected User $author;      // reporter, writes the article

    protected User $assignee;    // reporter, assigned for review

    protected User $otherReporter;

    protected User $plainUser;

    protected Category $category;

    protected Subcategory $subcategory;

    protected function setUpWorkflow(): void
    {
        $this->admin = User::factory()->admin()->create();
        $this->admin2 = User::factory()->admin()->create();
        $this->author = User::factory()->reporter()->create();
        $this->assignee = User::factory()->reporter()->create();
        $this->otherReporter = User::factory()->reporter()->create();
        $this->plainUser = User::factory()->create();

        $this->category = CategoryFactory::new()->create();
        $this->subcategory = SubcategoryFactory::new()->create(['category_id' => $this->category->id]);
        foreach ([$this->author, $this->assignee] as $r) {
            ReporterCategoryAssignment::create(['reporter_id' => $r->id, 'category_id' => $this->category->id, 'assigned_by_id' => $this->admin->id]);
        }
    }

    protected function article(ArticleStatus $status = ArticleStatus::DRAFT, array $attrs = []): Article
    {
        return Article::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'subcategory_id' => $this->subcategory->id,
            'status' => $status,
        ], $attrs));
    }

    /** POST an article action as $user (null = guest). */
    protected function act(?User $user, string $slug, string $action, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if ($user) {
            $this->actingAsUser($user);
        }

        return $this->postJson("/api/articles/{$slug}/{$action}/", $body);
    }

    protected function getAs(?User $user, string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if ($user) {
            $this->actingAsUser($user);
        }

        return $this->getJson($uri);
    }

    protected function statusOf(Article $a): ArticleStatus
    {
        return $a->fresh()->status;
    }
}
