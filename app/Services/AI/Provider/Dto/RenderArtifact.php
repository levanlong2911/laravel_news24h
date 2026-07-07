<?php

namespace App\Services\AI\Provider\Dto;

/**
 * Final output of a completed video render job.
 * Stored in pipeline_runs.output_json and later in VideoShot.video_url.
 */
final class RenderArtifact
{
    public function __construct(
        public readonly string  $taskId,
        public readonly string  $videoUrl,
        public readonly ?string $thumbnailUrl,
        public readonly float   $durationSeconds,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task_id'          => $this->taskId,
            'video_url'        => $this->videoUrl,
            'thumbnail_url'    => $this->thumbnailUrl,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
