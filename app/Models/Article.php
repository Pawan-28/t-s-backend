<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\ArticleStatus;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Subcategory is the primary classification. `category_id` is a legacy mirror
 * that is ALWAYS re-derived from the subcategory in saving(); category and
 * industry reported by the API come from effective_category / effective_industry.
 *
 * The author is immutable: `author_id` cannot change after creation.
 * Full-text data lives in `article_search_index`, maintained by App\Services\Search\ArticleSearchIndexer.
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /** TEXT/JSON columns cannot carry a DB default on MySQL, so the defaults live here. */
    protected $attributes = [
        'excerpt' => '',
        'location_name' => '',
        'faqs' => '[]',
        'rejection_reason' => '',
        'status' => 'DRAFT',
        'access_level' => 'PUBLIC',
    ];

    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'access_level' => AccessLevel::class,
            'faqs' => 'array',
            'scheduled_publish_at' => 'datetime',
            'published_at' => 'datetime',
            'subscribers_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Article $article) {
            // Legacy category always mirrors the subcategory's category.
            if ($article->subcategory_id) {
                $article->category_id = Subcategory::query()
                    ->whereKey($article->subcategory_id)
                    ->value('category_id');
            }
            // Author immutability: once persisted it can never change.
            if ($article->exists && $article->isDirty('author_id')) {
                $article->author_id = $article->getOriginal('author_id');
            }
        });
    }

    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(AiAnalysisResult::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function assignedReporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reporter_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function legacyCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'article_tag');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class)->orderBy('display_order')->orderBy('created_at');
    }

    public function featuredImage(): HasOne
    {
        return $this->hasOne(ArticleImage::class)->where('is_featured', true)->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PublishingSchedule::class);
    }

    public function dailyViews(): HasMany
    {
        return $this->hasMany(ArticleDailyView::class);
    }

    /** Category derived from the subcategory (fallback: legacy category). */
    public function getEffectiveCategoryAttribute(): ?Category
    {
        return $this->subcategory?->category ?? $this->legacyCategory;
    }

    public function getEffectiveIndustryAttribute(): ?Industry
    {
        return $this->effective_category?->industry;
    }
}
