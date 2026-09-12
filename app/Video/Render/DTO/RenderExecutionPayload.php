<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

final class RenderExecutionPayload
{
    public function __construct(
        public readonly string $renderId,
        public readonly string $requestHash,
        public readonly string $renderRequestJson,
    ) {
    }
}

