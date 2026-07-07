<?php

namespace App\Services\AI\Provider\Kling\Dto;

/**
 * The final output of a completed Kling video task.
 * Stored in pipeline_runs.video_url after the render job completes.
 */
final class VideoArtifact
{
    public function __construct(
        public readonly string  $taskId,
        public readonly string  $videoUrl,
        public readonly ?string $thumbnailUrl,
        public readonly float   $durationSeconds,
    ) {}

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
