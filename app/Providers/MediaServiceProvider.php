<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Category;
use App\Services\Media\ArticleImageService;
use App\Services\Media\BunnyStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Media wiring: when an Article is deleted its Bunny objects are removed
 * (best effort, logged, never blocking); Category image objects are removed
 * on category delete and when image_storage_path is replaced.
 * Model-level events only: bulk query deletes bypass them by design.
 */
class MediaServiceProvider extends ServiceProvider
{
    /** @var array<int, list<string>> article id => storage paths collected in `deleting` */
    private static array $pending = [];

    public function boot(): void
    {
        Article::deleting(function (Article $article) {
            try {
                self::$pending[$article->getKey()] = ArticleImage::query()
                    ->where('article_id', $article->getKey())
                    ->pluck('bunny_storage_path')
                    ->filter()
                    ->values()
                    ->all();
            } catch (Throwable $e) {
                Log::warning('Could not collect article images before delete', ['article_id' => $article->getKey()]);
            }
        });

        Article::deleted(function (Article $article) {
            $paths = self::$pending[$article->getKey()] ?? [];
            unset(self::$pending[$article->getKey()]);
            if ($paths === []) {
                return;
            }
            // Only after the DB delete really committed; failures never bubble up.
            DB::afterCommit(function () use ($article, $paths) {
                try {
                    app(ArticleImageService::class)->purgePaths($article, $paths);
                } catch (Throwable $e) {
                    Log::warning('Bunny cleanup after article delete failed', ['article_id' => $article->getKey(), 'error' => class_basename($e)]);
                }
            });
        });

        Category::deleted(function (Category $category) {
            $this->deleteCategoryObject((string) $category->getOriginal('image_storage_path'), $category->getKey());
        });

        Category::updated(function (Category $category) {
            if ($category->wasChanged('image_storage_path')) {
                $old = (string) $category->getOriginal('image_storage_path');
                if ($old !== (string) $category->image_storage_path) {
                    $this->deleteCategoryObject($old, $category->getKey());
                }
            }
        });
    }

    private function deleteCategoryObject(string $path, int|string $categoryId): void
    {
        if ($path === '' || ! preg_match('#^categories/[A-Za-z0-9._/-]+$#', $path) || str_contains($path, '..')) {
            return; // not one of our own uploads (e.g. a pasted URL) - never touch it
        }
        DB::afterCommit(function () use ($path, $categoryId) {
            try {
                $inUse = Category::query()->where('image_storage_path', $path)->where('id', '!=', $categoryId)->exists();
                if (! $inUse) {
                    app(BunnyStorage::class)->delete($path);
                }
            } catch (Throwable $e) {
                Log::warning('Bunny cleanup for category image failed', ['category_id' => $categoryId]);
            }
        });
    }
}
