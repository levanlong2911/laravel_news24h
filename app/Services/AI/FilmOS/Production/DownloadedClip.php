<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

/**
 * A successfully downloaded video clip, ready for FFmpeg processing.
 */
final class DownloadedClip
{
    public function __construct(
        public readonly string $shotId,
        public readonly string $localPath,
        public readonly int    $sizeBytes,
        public readonly string $sha256,
        public readonly int    $ordinal,    // position in the final sequence (0-based)
    ) {}

    public function exists(): bool
    {
        return file_exists($this->localPath) && filesize($this->localPath) === $this->sizeBytes;
    }
}
