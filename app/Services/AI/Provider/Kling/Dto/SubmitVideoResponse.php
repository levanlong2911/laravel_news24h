<?php

namespace App\Services\AI\Provider\Kling\Dto;

use App\Services\AI\Provider\Kling\KlingVideoStatus;

final class SubmitVideoResponse
{
    public function __construct(
        public readonly string          $taskId,
        public readonly KlingVideoStatus $status,
        public readonly string          $requestId,
    ) {}

    public function toArray(): array
    {
        return [
            'task_id'    => $this->taskId,
            'status'     => $this->status->value,
            'request_id' => $this->requestId,
        ];
    }
}
