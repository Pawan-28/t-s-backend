<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/** Shared fixtures for the Media / Bunny / Image / Ads tests (no real network, no real credentials). */
trait MediaTestHelpers
{
    protected const BUNNY_KEY = 'TEST-ACCESS-KEY-do-not-leak-9f8e7d';

    protected function configureBunny(array $override = []): void
    {
        config(['portal.bunny' => array_merge([
            'storage_zone' => 'testzone',
            'api_key' => self::BUNNY_KEY,
            'region' => 'de',
            'pull_zone_url' => 'https://Test-CDN.b-cdn.net',
            'timeout' => 5,
        ], $override)]);
        config(['portal.images' => array_merge(config('portal.images'), ['max_upload_mb' => 5, 'max_dimension_px' => 2000])]);
    }

    /** Fake Bunny: PUT -> $put, DELETE -> $delete (status codes). */
    protected function fakeBunny(int $put = 201, int $delete = 200): void
    {
        Http::fake(fn (HttpRequest $r) => match ($r->method()) {
            'PUT' => Http::response('', $put),
            'DELETE' => Http::response('', $delete),
            default => Http::response('', 500),
        });
    }

    /** Requests recorded against Bunny for a method. @return list<HttpRequest> */
    protected function bunnyRequests(string $method): array
    {
        $out = [];
        foreach (Http::recorded() as [$req]) {
            if ($req->method() === $method) {
                $out[] = $req;
            }
        }

        return $out;
    }

    /** Raw bytes of a synthetic image. $kind: jpeg|png|webp|gif, $alpha => real alpha channel (png/webp). */
    protected function imageBytes(int $w = 200, int $h = 100, string $kind = 'jpeg', bool $alpha = false, ?int $exifOrientation = null): string
    {
        $img = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagefilledrectangle($img, 0, 0, intdiv($w, 2), $h, imagecolorallocatealpha($img, 200, 30, 30, 0));
        } else {
            imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 255));          // blue background
            imagefilledrectangle($img, 0, 0, intdiv($w, 2) - 1, intdiv($h, 2) - 1, imagecolorallocate($img, 255, 0, 0)); // red top-left marker
        }
        ob_start();
        match ($kind) {
            'png' => imagepng($img),
            'webp' => imagewebp($img, null, 90),
            'gif' => imagegif($img),
            default => imagejpeg($img, null, 90),
        };
        $bytes = (string) ob_get_clean();

        if ($exifOrientation !== null && $kind === 'jpeg') {
            $tiff = "II*\x00\x08\x00\x00\x00".pack('v', 1).pack('vvVv', 0x0112, 3, 1, $exifOrientation)."\x00\x00".pack('V', 0);
            $payload = "Exif\x00\x00".$tiff;
            $bytes = substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($bytes, 2);
        }

        return $bytes;
    }

    protected function upload(string $bytes, string $name = 'photo.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);
    }

    /** Decode response-facing facts of a processed image stored in a captured Bunny PUT. */
    protected function decodeBody(HttpRequest $r): array
    {
        $info = getimagesizefromstring($r->body());

        return ['w' => $info[0], 'h' => $info[1], 'mime' => $info['mime'], 'bytes' => $r->body()];
    }
}
