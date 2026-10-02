<?php

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'excerpt' => fake()->sentence(12),
            'content' => '<p>'.fake()->paragraph(4).'</p>',
            'location_name' => '',
            'faqs' => [],
            'subcategory_id' => SubcategoryFactory::new(),
            'author_id' => UserFactory::new()->reporter(),
            'status' => ArticleStatus::DRAFT,
            'access_level' => AccessLevel::PUBLIC,
            'rejection_reason' => '',
        ];
    }

    public function status(ArticleStatus $s): static
    {
        return $this->state(['status' => $s]);
    }

    public function published(): static
    {
        return $this->state(['status' => ArticleStatus::PUBLISHED, 'published_at' => now()->subMinute()]);
    }

    public function access(AccessLevel $l): static
    {
        return $this->state(['access_level' => $l]);
    }
}
