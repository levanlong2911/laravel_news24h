<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Intent;

final class DirectorIntent
{
    public function __construct(
        public readonly string            $productionId,
        public readonly string            $shotId,
        public readonly string            $decisionDagId,
        public readonly MeaningContext    $meaning,
        public readonly ExecutionContext  $execution,
        public readonly EvaluationContext $evaluation,
    ) {}
}
