<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ArticleImage> */
class ArticleImageFactory extends Factory
{
    protected $model = ArticleImage::class;

    public function definition(): array
    {
        $hex = Str::lower(Str::random(32));

        return [
            'article_id' => ArticleFactory::new(),
            'uploaded_by_id' => UserFactory::new()->reporter(),
            'bunny_url' => 'https://test-cdn.b-cdn.net/articles/x/'.$hex.'.jpg',
            'bunny_storage_path' => 'articles/x/'.$hex.'.jpg',
            'alt_text' => '',
            'caption' => '',
            'is_featured' => false,
            'display_order' => 0,
            'metadata' => [
                'original_filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'file_size_bytes' => 1234,
                'width' => 800, 'height' => 600, 'checksum' => hash('sha256', $hex),
            ],
        ];
    }

    /** Point the row at the article's own storage prefix (what the upload pipeline produces). */
    public function forArticle(Article $article): static
    {
        $hex = Str::lower(Str::random(32));

        return $this->state([
            'article_id' => $article->id,
            'bunny_url' => 'https://test-cdn.b-cdn.net/articles/'.$article->slug.'/'.$hex.'.jpg',
            'bunny_storage_path' => 'articles/'.$article->slug.'/'.$hex.'.jpg',
        ]);
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }
}
