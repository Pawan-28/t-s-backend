<?php

namespace App\Services\Media;

use App\Models\Advertisement;
use App\Models\ArticleImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Advertisement creatives: same validate -> resize -> Bunny pipeline as article
 * images (Django reused it), stored under advertisements/{uuid}.{ext}.
 * Old creatives are removed after the DB change committed; only paths inside
 * advertisements/ that no other row references are ever deleted.
 */
class AdvertisementService
{
    public function __construct(
        private BunnyStorage $bunny,
        private ImageProcessor $processor,
    ) {}

    /**
     * @param  array<string, mixed>  $attrs  validated column values
     *
     * @throws ImageValidationException
     * @throws BunnyStorageException
     */
    public function create(array $attrs, UploadedFile $creative): Advertisement
    {
        [$path, $url] = $this->uploadCreative($creative);
        try {
            return Advertisement::query()->create($attrs + ['image_url' => $url, 'bunny_storage_path' => $path])->refresh();
        } catch (Throwable $e) {
            $this->bunny->delete($path);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     *
     * @throws ImageValidationException
     * @throws BunnyStorageException
     */
    public function update(Advertisement $ad, array $attrs, ?UploadedFile $creative = null): Advertisement
    {
        $newPath = $newUrl = null;
        if ($creative) {
            [$newPath, $newUrl] = $this->uploadCreative($creative);
        }

        $oldPath = null;
        try {
            $fresh = DB::transaction(function () use ($ad, $attrs, $newPath, $newUrl, &$oldPath) {
                $row = Advertisement::query()->whereKey($ad->id)->lockForUpdate()->firstOrFail();
                $row->fill($attrs);
                if ($newPath !== null) {
                    $oldPath = $this->ownedPath($row);
                    $row->image_url = $newUrl;
                    $row->bunny_storage_path = $newPath;
                }
                $row->save();

                return $row->refresh();
            }, 3);
        } catch (Throwable $e) {
            if ($newPath !== null) {
                $this->bunny->delete($newPath);
            }
            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $newPath) {
            $this->bunny->delete($oldPath);
        }

        return $fresh;
    }

    public function delete(Advertisement $ad): void
    {
        $path = null;
        DB::transaction(function () use ($ad, &$path) {
            $row = Advertisement::query()->whereKey($ad->id)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $path = $this->ownedPath($row);
            $row->delete();
        }, 3);
        if ($path !== null) {
            $this->bunny->delete($path);
        } elseif ($ad->bunny_storage_path) {
            Log::warning('Ad creative not deleted from Bunny (ownership guard)', ['ad_id' => $ad->id]);
        }
    }

    /** @return array{0: string, 1: string} [storage path, public url] */
    private function uploadCreative(UploadedFile $file): array
    {
        $p = $this->processor->processUpload($file);
        $path = 'advertisements/'.Str::lower(str_replace('-', '', (string) Str::uuid())).'.'.$p->extension;

        return [$path, $this->bunny->upload($path, $p->bytes, $p->contentType)];
    }

    /** The ad's own creative path iff it is safe to delete (blank when the URL was pasted / imported). */
    private function ownedPath(Advertisement $ad): ?string
    {
        $path = (string) $ad->bunny_storage_path;
        if (! preg_match('#^advertisements/[A-Za-z0-9._-]+$#', $path) || str_contains($path, '..')) {
            return null;
        }
        $shared = Advertisement::query()->where('bunny_storage_path', $path)->where('id', '!=', $ad->id)->exists()
            || ArticleImage::query()->where('bunny_storage_path', $path)->exists();

        return $shared ? null : $path;
    }
}
