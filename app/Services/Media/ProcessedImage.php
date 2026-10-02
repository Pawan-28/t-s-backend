<?php

namespace App\Services\Media;

/** Result of ImageProcessor: the re-encoded bytes plus the facts we persist. */
final class ProcessedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
        public readonly string $checksum,
    ) {}

    public function size(): int
    {
        return strlen($this->bytes);
    }
}
