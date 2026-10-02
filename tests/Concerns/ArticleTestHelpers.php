<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Industry;
use App\Models\Subcategory;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\EntitlementService;

/** Helpers for the taxonomy / articles / search test-suites (real bearer tokens, so public endpoints personalise). */
trait ArticleTestHelpers
{
    /** Act as $user (real ACCESS token) or as a guest (null); safe to call between requests in one test. */
    protected function as(?User $user): static
    {
        $this->app['auth']->forgetGuards();
        app(EntitlementService::class)->flush();
        $this->defaultHeaders = [];
        if ($user) {
            $this->withToken($user->createToken('access', ['access'])->plainTextToken);
        }

        return $this;
    }

    /** @return array{industry: Industry, category: Category, subcategory: Subcategory} */
    protected function tree(array $industry = [], array $category = [], array $subcategory = []): array
    {
        $i = Industry::factory()->create($industry);
        $c = Category::factory()->create(['industry_id' => $i->id] + $category);
        $s = Subcategory::factory()->create(['category_id' => $c->id] + $subcategory);

        return ['industry' => $i, 'category' => $c, 'subcategory' => $s];
    }

    protected function subscriberWithPlan(string $status = Subscription::ACTIVE, ?\DateTimeInterface $expires = null): User
    {
        $user = User::factory()->subscriber()->create();
        $plan = SubscriptionPlan::query()->firstOrCreate(
            ['slug' => 'test-plan'],
            ['name' => 'Test', 'description' => '', 'price_amount' => 100, 'price_currency' => 'INR', 'duration_days' => 30, 'is_active' => true]
        );
        Subscription::query()->create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => $status,
            'started_at' => now()->subDays(5), 'expires_at' => $expires ?? now()->addDays(20),
        ]);

        return $user;
    }
}
