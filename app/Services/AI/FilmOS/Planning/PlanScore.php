<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanScore
{
    public function __construct(
        public readonly float $narrativeScore,
        public readonly float $estimatedCostUsd,
        public readonly int   $estimatedLatencyMs,
        public readonly float $expectedReviewScore,
        public readonly float $composite,
    ) {}

    public function meetsHardCaps(PlanObjectives $obj): bool
    {
        return $this->estimatedCostUsd    <= $obj->maxCostUsd
            && $this->estimatedLatencyMs  <= $obj->maxLatencyMs
            && $this->expectedReviewScore >= $obj->minReviewScore;
    }
}
