<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

use DateTimeImmutable;

final class RenderRecoveryClaim
{
    public function __construct(
        public readonly string $renderId,
        public readonly int $attemptNo,
        public readonly string $claimToken,
        public readonly int $claimGeneration,
        public readonly DateTimeImmutable $leaseExpiresAt,
    ) {
    }
}

