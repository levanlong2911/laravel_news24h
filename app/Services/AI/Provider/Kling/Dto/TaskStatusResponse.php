<?php

namespace App\Services\AI\Provider\Kling\Dto;

use App\Services\AI\Provider\Kling\KlingVideoStatus;

final class TaskStatusResponse
{
    public function __construct(
        public readonly string           $taskId,
        public readonly KlingVideoStatus  $status,
        public readonly string           $requestId,
        public readonly ?string          $videoUrl,
        public readonly ?string          $thumbnailUrl,
        public readonly ?string          $errorMessage,
        public readonly ?float           $durationSeconds,
    ) {}

    public function toArray(): array
    {
        return [
            'task_id'          => $this->taskId,
            'status'           => $this->status->value,
            'request_id'       => $this->requestId,
            'video_url'        => $this->videoUrl,
            'thumbnail_url'    => $this->thumbnailUrl,
            'error_message'    => $this->errorMessage,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
