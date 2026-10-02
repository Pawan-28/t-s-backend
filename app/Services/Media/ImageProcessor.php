<?php

namespace App\Services\Media;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Validate -> orient -> resize-down -> re-encode (Django ImageValidationService +
 * ImageProcessingService ported to GD).
 *
 *  - never trusts filename/Content-Type: the bytes must actually DECODE, and the
 *    detected type must be JPEG/PNG/WebP/GIF;
 *  - file size cap = portal.images.max_upload_mb; below min_dimension_px is rejected;
 *  - images larger than portal.images.max_dimension_px are RESIZED DOWN (aspect
 *    ratio kept, never upscaled), not rejected;
 *  - EXIF orientation is applied, all metadata is stripped by the re-encode;
 *  - images with a real alpha channel stay PNG, everything else becomes JPEG.
 */
class ImageProcessor
{
    /** Decode guard (GD keeps ~4 bytes/pixel in memory); Django/Pillow refuses far larger, but PHP memory is finite. */
    public const MAX_PIXELS = 50_000_000;

    private const ALLOWED = [
        IMAGETYPE_JPEG => 'JPEG',
        IMAGETYPE_PNG => 'PNG',
        IMAGETYPE_WEBP => 'WebP',
        IMAGETYPE_GIF => 'GIF',
    ];

    public const MSG_NOT_IMAGE = 'Upload a valid image. The file you uploaded was either not an image or a corrupted image.';

    public function maxUploadBytes(): int
    {
        return max(1, (int) config('portal.images.max_upload_mb', 5)) * 1024 * 1024;
    }

    /** Reads + processes an uploaded file, translating PHP upload errors into user messages. */
    public function processUpload(UploadedFile $file): ProcessedImage
    {
        $err = $file->getError();
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new ImageValidationException($this->tooLargeMessage());
        }
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new ImageValidationException('No file was submitted.');
        }
        if ($err !== UPLOAD_ERR_OK || ! $file->isValid()) {
            throw new ImageValidationException('The file upload failed. Please try again.');
        }
        $path = $file->getRealPath();
        $size = $path ? (int) filesize($path) : 0;
        if ($size <= 0) {
            throw new ImageValidationException('The submitted file is empty.');
        }
        if ($size > $this->maxUploadBytes()) {
            throw new ImageValidationException($this->tooLargeMessage());
        }
        $bytes = (string) file_get_contents($path);

        return $this->process($bytes);
    }

    public function process(string $bytes): ProcessedImage
    {
        if ($bytes === '') {
            throw new ImageValidationException('The submitted file is empty.');
        }
        if (strlen($bytes) > $this->maxUploadBytes()) {
            throw new ImageValidationException($this->tooLargeMessage());
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! isset($info[0], $info[1], $info[2])) {
            throw new ImageValidationException(self::MSG_NOT_IMAGE);
        }
        [$w, $h, $type] = $info;
        if (! isset(self::ALLOWED[$type])) {
            $name = strtoupper(ltrim((string) image_type_to_extension($type), '.')) ?: 'unknown';
            throw new ImageValidationException("Unsupported image format '{$name}'. Allowed formats: JPEG, PNG, WebP, GIF.");
        }
        if ($w < 1 || $h < 1) {
            throw new ImageValidationException(self::MSG_NOT_IMAGE);
        }
        $min = (int) config('portal.images.min_dimension_px', 32);
        if ($w < $min || $h < $min) {
            throw new ImageValidationException("Image is too small ({$w}x{$h}px); minimum is {$min}x{$min}px.");
        }
        if ($w * $h > self::MAX_PIXELS || ! $this->fitsInMemory($w, $h)) {
            // Decompression-bomb guard: a tiny file can declare a gigantic canvas; GD would exhaust the PHP
            // memory limit (a fatal error, not an exception) while decoding it.
            throw new ImageValidationException('Image resolution is too large to process safely.');
        }

        $img = $this->decode($bytes);
        if ($img === null) {
            throw new ImageValidationException(self::MSG_NOT_IMAGE);
        }

        if ($type === IMAGETYPE_JPEG) {
            $img = $this->applyOrientation($img, $this->exifOrientation($bytes));
        }
        $hasAlpha = $this->hasAlpha($bytes, $type);
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        // Resize DOWN only, preserving aspect ratio.
        $max = max(1, (int) config('portal.images.max_dimension_px', 2000));
        $cw = imagesx($img);
        $ch = imagesy($img);
        if ($cw > $max || $ch > $max) {
            $scale = min($max / $cw, $max / $ch);
            $nw = max(1, (int) round($cw * $scale));
            $nh = max(1, (int) round($ch * $scale));
            $img = $this->resample($img, $nw, $nh, $hasAlpha);
            $cw = $nw;
            $ch = $nh;
        }

        if ($hasAlpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $out = $this->encode(fn () => imagepng($img, null, 9));
            $ct = 'image/png';
            $ext = 'png';
        } else {
            if ($type !== IMAGETYPE_JPEG) {
                $img = $this->flattenOnWhite($img);
            }
            $out = $this->encode(fn () => imagejpeg($img, null, (int) config('portal.images.jpeg_quality', 85)));
            $ct = 'image/jpeg';
            $ext = 'jpg';
        }
        if ($out === '') {
            throw new ImageValidationException('The image could not be processed.');
        }

        return new ProcessedImage($out, $ct, $ext, $cw, $ch, hash('sha256', $out));
    }

    /** GD holds ~4 bytes/pixel per bitmap; decode + resample/re-encode needs a few copies. */
    private function fitsInMemory(int $w, int $h): bool
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return true; // memory_limit = -1
        }
        $needed = (int) ($w * $h * 4 * 3);

        return $needed + memory_get_usage() < $limit;
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }
        $n = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function tooLargeMessage(): string
    {
        return 'Image exceeds the maximum upload size of '.intdiv($this->maxUploadBytes(), 1024 * 1024).'MB.';
    }

    private function decode(string $bytes): ?GdImage
    {
        set_error_handler(static fn () => true);
        try {
            $img = imagecreatefromstring($bytes);
        } catch (\Throwable) {
            $img = false;
        } finally {
            restore_error_handler();
        }

        return $img instanceof GdImage ? $img : null;
    }

    private function encode(callable $fn): string
    {
        ob_start();
        try {
            $ok = $fn();
            $data = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return $ok ? $data : '';
    }

    private function exifOrientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return 1;
        }
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            set_error_handler(static fn () => true);
            try {
                $exif = exif_read_data($stream, 'IFD0');
            } finally {
                restore_error_handler();
            }
        } catch (\Throwable) {
            $exif = false;
        } finally {
            fclose($stream);
        }
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $o >= 1 && $o <= 8 ? $o : 1;
    }

    /** Same mapping as Pillow's ImageOps.exif_transpose. GD's imagerotate is counter-clockwise. */
    private function applyOrientation(GdImage $img, int $o): GdImage
    {
        return match ($o) {
            2 => $this->flip($img, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($img, 180),
            4 => $this->flip($img, IMG_FLIP_VERTICAL),
            5 => $this->flip($this->rotate($img, 90), IMG_FLIP_VERTICAL),
            6 => $this->rotate($img, -90),
            7 => $this->flip($this->rotate($img, -90), IMG_FLIP_VERTICAL),
            8 => $this->rotate($img, 90),
            default => $img,
        };
    }

    private function rotate(GdImage $img, int $deg): GdImage
    {
        $r = imagerotate($img, $deg, 0);

        return $r instanceof GdImage ? $r : $img;
    }

    private function flip(GdImage $img, int $mode): GdImage
    {
        imageflip($img, $mode);

        return $img;
    }

    private function resample(GdImage $src, int $nw, int $nh, bool $alpha): GdImage
    {
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, imagesx($src), imagesy($src));

        return $dst;
    }

    /** JPEG has no alpha: paint any residual transparency on white (opaque sources are untouched). */
    private function flattenOnWhite(GdImage $img): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagealphablending($dst, true);
        imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);

        return $dst;
    }

    /** Header-level alpha detection (Pillow: RGBA/LA or palette+transparency stays PNG). */
    private function hasAlpha(string $b, int $type): bool
    {
        switch ($type) {
            case IMAGETYPE_PNG:
                $ct = ord($b[25] ?? "\0");
                if ($ct === 4 || $ct === 6) {
                    return true;
                }
                $idat = strpos($b, 'IDAT');
                $trns = strpos($b, 'tRNS');

                return $trns !== false && ($idat === false || $trns < $idat);
            case IMAGETYPE_WEBP:
                $fourcc = substr($b, 12, 4);
                if ($fourcc === 'VP8X') {
                    return (ord($b[20] ?? "\0") & 0x10) !== 0;
                }
                if ($fourcc === 'VP8L' && strlen($b) >= 25) {
                    $v = unpack('V', substr($b, 21, 4))[1];

                    return (($v >> 28) & 1) === 1;
                }

                return false;
            case IMAGETYPE_GIF:
                $p = strpos($b, "\x21\xF9\x04");

                return $p !== false && (ord($b[$p + 3] ?? "\0") & 1) === 1;
            default:
                return false;
        }
    }
}
