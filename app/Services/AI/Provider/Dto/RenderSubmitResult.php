<?php

namespace App\Services\AI\Provider\Dto;

use App\Services\AI\Provider\RenderVideoStatus;

final class RenderSubmitResult
{
    public function __construct(
        public readonly string           $taskId,
        public readonly RenderVideoStatus $status,
        public readonly string           $requestId,
    ) {}
}
