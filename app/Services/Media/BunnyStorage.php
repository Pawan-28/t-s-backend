<?php

namespace App\Services\Media;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for Bunny.net Edge Storage (ported from Django BunnyStorageClient).
 *
 *   PUT    https://{host}/{zone}/{path}      (AccessKey header)
 *   DELETE https://{host}/{zone}/{path}
 *
 * Public URL = {pull zone URL}/{path}. Only the HOSTNAME of the pull-zone URL is
 * lower-cased (next/image remotePatterns are case-sensitive; see the Bunny
 * hostname fix reports); scheme/path are untouched. Region "", "storage", "de"
 * and "falkenstein" all use storage.bunnycdn.com, every other region uses
 * "{region}.storage.bunnycdn.com". The AccessKey is never logged or returned.
 */
class BunnyStorage
{
    private string $zone;

    private string $key;

    private string $region;

    private string $pullZone;

    private int $timeout;

    public function __construct()
    {
        $c = (array) config('portal.bunny');
        $this->zone = trim((string) ($c['storage_zone'] ?? ''), '/');
        $this->key = (string) ($c['api_key'] ?? '');
        $this->region = strtolower(trim((string) ($c['region'] ?? '')));
        $this->pullZone = self::normalizePullZone((string) ($c['pull_zone_url'] ?? ''));
        $this->timeout = (int) ($c['timeout'] ?? 30);
    }

    public static function normalizePullZone(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (! preg_match('#^([a-z][a-z0-9+.-]*://)([^/?\#]*)(.*)$#is', $url, $m)) {
            return $url;
        }

        return $m[1].strtolower($m[2]).$m[3];
    }

    public function isConfigured(): bool
    {
        return $this->zone !== '' && $this->key !== '' && $this->pullZone !== '';
    }

    public function storageHost(): string
    {
        return in_array($this->region, ['', 'storage', 'de', 'falkenstein'], true)
            ? 'storage.bunnycdn.com'
            : $this->region.'.storage.bunnycdn.com';
    }

    public function publicUrl(string $path): string
    {
        return $this->pullZone.'/'.ltrim($path, '/');
    }

    /** Uploads bytes and returns the public pull-zone URL. Throws BunnyStorageException on any failure. */
    public function upload(string $path, string $bytes, string $contentType): string
    {
        $path = $this->cleanPath($path);
        if (! $this->isConfigured()) {
            throw new BunnyStorageException('Bunny.net storage is not configured.');
        }
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['AccessKey' => $this->key])
                ->withBody($bytes, $contentType)
                ->put($this->endpoint($path));
        } catch (ConnectionException $e) {
            Log::warning('Bunny upload connection failure', ['path' => $path]);
            throw new BunnyStorageException('Bunny.net upload failed: connection error.', 0, $e);
        }

        if (! in_array($response->status(), [200, 201], true)) {
            Log::warning('Bunny upload rejected', ['path' => $path, 'status' => $response->status()]);
            throw new BunnyStorageException('Bunny.net upload returned HTTP '.$response->status().'.');
        }

        return $this->publicUrl($path);
    }

    /** Deletes an object. 404 counts as success. Never throws; false = could not confirm deletion (logged). */
    public function delete(string $path): bool
    {
        try {
            $path = $this->cleanPath($path);
            if (! $this->isConfigured()) {
                Log::warning('Bunny delete skipped: storage not configured', ['path' => $path]);

                return false;
            }
            $response = Http::timeout($this->timeout)
                ->withHeaders(['AccessKey' => $this->key])
                ->delete($this->endpoint($path));
            if (in_array($response->status(), [200, 204, 404], true)) {
                return true;
            }
            Log::warning('Bunny delete failed', ['path' => $path, 'status' => $response->status()]);
        } catch (Throwable $e) {
            Log::warning('Bunny delete error', ['path' => $path ?? null, 'error' => class_basename($e)]);
        }

        return false;
    }

    private function endpoint(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        $base = rtrim((string) config('portal.bunny.api_base'), '/');
        $origin = $base !== '' ? $base : 'https://'.$this->storageHost();

        return $origin.'/'.rawurlencode($this->zone).'/'.$encoded;
    }

    private function cleanPath(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, '//')) {
            throw new BunnyStorageException('Invalid storage path.');
        }

        return $path;
    }
}
