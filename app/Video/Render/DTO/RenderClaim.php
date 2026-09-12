<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

use DateTimeImmutable;

final class RenderClaim
{
    public function __construct(
        public readonly string $renderId,
        public readonly string $claimToken,
        public readonly string $workerId,
        public readonly int $attemptNo,
        public readonly int $claimGeneration,
        public readonly DateTimeImmutable $leaseExpiresAt,
        public readonly string $renderRequestJson,
        public readonly string $requestHash,
    ) {
    }
}

