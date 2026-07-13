<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

final class PromptEdge
{
    public function __construct(
        public readonly string $fromId,
        public readonly string $toId,
        public readonly string $relation = '',
        public readonly float  $weight   = 1.0,
    ) {}
}
