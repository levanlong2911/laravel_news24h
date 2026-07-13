<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * One rendered artifact in the artifact bundle.
 *
 * contentHash is sha256 of the artifact content:
 *   - dry-run / mock:  sha256(videoUrl) — URL is deterministic, so hash is too
 *   - live provider:   sha256(video_bytes) — caller pre-computes from downloaded bytes
 *
 * Excluded from toArray(): sizeBytes — runtime metadata, not determinism-relevant.
 */
final class ArtifactEntry
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $artifactType,
        public readonly string $contentHash,
    ) {}

    public function toArray(): array
    {
        return [
            'taskId'       => $this->taskId,
            'artifactType' => $this->artifactType,
            'contentHash'  => $this->contentHash,
        ];
    }
}
