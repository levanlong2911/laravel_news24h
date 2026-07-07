<?php

namespace App\Services\AI\Provider\Dto;

use App\Services\AI\Provider\RenderVideoStatus;

final class RenderStatusResult
{
    public function __construct(
        public readonly string            $taskId,
        public readonly RenderVideoStatus  $status,
        public readonly string            $requestId,
        public readonly ?string           $videoUrl,
        public readonly ?string           $thumbnailUrl,
        public readonly ?string           $errorMessage,
        public readonly ?float            $durationSeconds,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /** @return array<string, mixed> */
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
