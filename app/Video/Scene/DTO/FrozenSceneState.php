<?php

declare(strict_types=1);

namespace App\Video\Scene\DTO;

final readonly class FrozenSceneState
{
    /** @param array<string, mixed> $graphJson */
    public function __construct(
        public array $graphJson,
        public string $graphHash,
    ) {
    }
}
