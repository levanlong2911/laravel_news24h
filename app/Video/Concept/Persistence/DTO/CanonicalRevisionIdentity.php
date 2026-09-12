<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\DTO;

final class CanonicalRevisionIdentity
{
    public function __construct(
        public readonly string $revisionId,
        public readonly string $projectId,
        public readonly ?string $sessionId,
        public readonly int $revision,
        public readonly string $canonicalHash,
    ) {}
}
