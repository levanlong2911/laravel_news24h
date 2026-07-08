<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\Strategies;

final class DecisionCandidate
{
    public function __construct(
        public readonly string $strategyName,
        public readonly array  $execution,    // partial execution params
        public readonly float  $score,
        public readonly string $rationale,
    ) {}
}
