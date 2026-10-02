<?php

namespace App\Services\Media;

use App\Models\Advertisement;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pipeline: validate -> resize -> Bunny -> MySQL (Django ArticleImageService),
 * plus replace / delete / featured invariants.
 *
 * Invariants
 *  - The Bunny object is uploaded BEFORE any DB write; if the DB write fails the
 *    fresh object is removed again (no orphan). A Bunny failure never leaves a row.
 *  - Old objects are removed only AFTER the DB change committed, best effort.
 *  - An object is only ever deleted through ownedPath(): it must live under the
 *    article's own prefix (articles/{slug}/{file}) and no other row may reference it.
 *  - Exactly one is_featured image per article whenever it has images: the first
 *    image is featured automatically, is_featured=true elsewhere demotes the rest,
 *    deleting the featured image promotes the next one (display_order, created_at).
 */
class ArticleImageService
{
    public function __construct(
        private BunnyStorage $bunny,
        private ImageProcessor $processor,
    ) {}

    /**
     * @param  array{alt_text?:string,caption?:string,is_featured?:bool,display_order?:int}  $attrs
     *
     * @throws ImageValidationException
     * @throws BunnyStorageException
     */
    public function upload(Article $article, User $actor, UploadedFile $file, array $attrs = []): ArticleImage
    {
        $processed = $this->processor->processUpload($file);
        $path = $this->newPath($article, $processed);
        $url = $this->bunny->upload($path, $processed->bytes, $processed->contentType);

        try {
            return DB::transaction(function () use ($article, $actor, $file, $attrs, $processed, $path, $url) {
                $this->lockArticle($article);
                $hasFeatured = ArticleImage::query()->where('article_id', $article->id)->where('is_featured', true)->exists();
                $feature = ! empty($attrs['is_featured']) || ! $hasFeatured;
                if ($feature) {
                    ArticleImage::query()->where('article_id', $article->id)->where('is_featured', true)->update(['is_featured' => false]);
                }

                return ArticleImage::query()->create([
                    'article_id' => $article->id,
                    'uploaded_by_id' => $actor->id,
                    'bunny_url' => $url,
                    'bunny_storage_path' => $path,
                    'alt_text' => (string) ($attrs['alt_text'] ?? ''),
                    'caption' => (string) ($attrs['caption'] ?? ''),
                    'is_featured' => $feature,
                    'display_order' => (int) ($attrs['display_order'] ?? 0),
                    'metadata' => $this->metadata($file, $processed),
                ])->load('uploader');
            }, 3);
        } catch (Throwable $e) {
            $this->bunny->delete($path); // never leave an unreferenced object behind
            throw $e;
        }
    }

    /** Metadata-only edit. is_featured=true demotes siblings; false never leaves the article without a featured image. */
    public function update(ArticleImage $image, array $attrs): ArticleImage
    {
        return DB::transaction(function () use ($image, $attrs) {
            $article = $this->lockArticle($image->article_id);
            $image = ArticleImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();

            foreach (['alt_text', 'caption', 'display_order'] as $f) {
                if (array_key_exists($f, $attrs)) {
                    $image->{$f} = $attrs[$f];
                }
            }
            if (! empty($attrs['is_featured']) && ! $image->is_featured) {
                ArticleImage::query()->where('article_id', $article->id)->where('id', '!=', $image->id)->where('is_featured', true)->update(['is_featured' => false]);
                $image->is_featured = true;
            }
            $image->save();
            $this->ensureFeatured($article->id);

            return $image->fresh(['uploader']);
        }, 3);
    }

    /**
     * Upload the new object, swap it into the row, then delete the OLD object.
     *
     * @throws ImageValidationException
     * @throws BunnyStorageException
     */
    public function replace(ArticleImage $image, Article $article, UploadedFile $file): ArticleImage
    {
        $processed = $this->processor->processUpload($file);
        $newPath = $this->newPath($article, $processed);
        $newUrl = $this->bunny->upload($newPath, $processed->bytes, $processed->contentType);

        $oldPath = null;
        try {
            $fresh = DB::transaction(function () use ($image, $article, $file, $processed, $newPath, $newUrl, &$oldPath) {
                $this->lockArticle($article);
                $row = ArticleImage::query()->whereKey($image->id)->where('article_id', $article->id)->lockForUpdate()->firstOrFail();
                $oldPath = $this->ownedPath($row, $article);
                $row->bunny_url = $newUrl;
                $row->bunny_storage_path = $newPath;
                $row->metadata = $this->metadata($file, $processed);
                $row->save();

                return $row->fresh(['uploader']);
            }, 3);
        } catch (Throwable $e) {
            $this->bunny->delete($newPath);
            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $newPath) {
            $this->bunny->delete($oldPath);
        }

        return $fresh;
    }

    /** Removes the row (promoting another featured image if needed), then the Bunny object (404 tolerated). */
    public function delete(ArticleImage $image, Article $article): void
    {
        $oldPath = null;
        DB::transaction(function () use ($image, $article, &$oldPath) {
            $this->lockArticle($article);
            $row = ArticleImage::query()->whereKey($image->id)->where('article_id', $article->id)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $oldPath = $this->ownedPath($row, $article);
            $row->delete();
            $this->ensureFeatured($article->id);
        }, 3);

        if ($oldPath !== null) {
            $this->bunny->delete($oldPath);
        } elseif ($image->bunny_storage_path) {
            Log::warning('Bunny object not deleted: path is not owned by this article', ['image_id' => $image->id]);
        }
    }

    /**
     * Best-effort removal of all of an article's objects (used by the Article deleting hook).
     *
     * @param  list<string>  $paths
     */
    public function purgePaths(Article $article, array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if ($this->pathBelongsToArticle($path, $article) && ! $this->referencedElsewhere($path, null)) {
                $this->bunny->delete($path);
            } else {
                Log::warning('Bunny object skipped on article delete (ownership guard)', ['article_id' => $article->id]);
            }
        }
    }

    /** The image's storage path iff it may safely be deleted as part of THIS article, else null. */
    public function ownedPath(ArticleImage $image, Article $article): ?string
    {
        $path = (string) $image->bunny_storage_path;
        if ($image->article_id !== $article->id || ! $this->pathBelongsToArticle($path, $article)) {
            return null;
        }

        return $this->referencedElsewhere($path, $image->id) ? null : $path;
    }

    public function pathBelongsToArticle(string $path, Article $article): bool
    {
        $prefix = 'articles/'.$article->slug.'/';
        if (! str_starts_with($path, $prefix)) {
            return false;
        }
        $rest = substr($path, strlen($prefix));

        return $rest !== '' && ! str_contains($rest, '/') && ! str_contains($rest, '..') && $article->slug !== '';
    }

    private function referencedElsewhere(string $path, ?int $exceptImageId): bool
    {
        $q = ArticleImage::query()->where('bunny_storage_path', $path);
        if ($exceptImageId !== null) {
            $q->where('id', '!=', $exceptImageId);
        }

        return $q->exists() || Advertisement::query()->where('bunny_storage_path', $path)->exists();
    }

    private function newPath(Article $article, ProcessedImage $p): string
    {
        return 'articles/'.$article->slug.'/'.Str::lower(str_replace('-', '', (string) Str::uuid())).'.'.$p->extension;
    }

    private function metadata(UploadedFile $file, ProcessedImage $p): array
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName())) ?: 'upload';

        return [
            'original_filename' => mb_substr($name, 0, 255),
            'content_type' => $p->contentType,
            'file_size_bytes' => $p->size(),
            'width' => $p->width,
            'height' => $p->height,
            'checksum' => $p->checksum,
        ];
    }

    private function lockArticle(Article|int $article): Article
    {
        return Article::query()->whereKey($article instanceof Article ? $article->id : $article)->lockForUpdate()->firstOrFail();
    }

    /** Exactly one featured image: keep the earliest featured one, or promote the first image by display order. */
    private function ensureFeatured(int $articleId): void
    {
        $featured = ArticleImage::query()->where('article_id', $articleId)->where('is_featured', true)->orderBy('id')->pluck('id');
        if ($featured->count() > 1) {
            ArticleImage::query()->whereIn('id', $featured->slice(1)->all())->update(['is_featured' => false]);

            return;
        }
        if ($featured->isEmpty()) {
            $next = ArticleImage::query()->where('article_id', $articleId)->orderBy('display_order')->orderBy('created_at')->orderBy('id')->first();
            $next?->update(['is_featured' => true]);
        }
    }
}
