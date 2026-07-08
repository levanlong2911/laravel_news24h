<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Evaluation\Plugins;

final class EvaluationResult
{
    public function __construct(
        public readonly string $shotId,
        public readonly bool   $accepted,
        public readonly float  $score,
        public readonly array  $issues,
    ) {}
}
