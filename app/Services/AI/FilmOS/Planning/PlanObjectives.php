<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanObjectives
{
    public function __construct(
        public readonly float $narrativeWeight,
        public readonly float $costWeight,
        public readonly float $latencyWeight,
        public readonly float $reviewScoreWeight,
        public readonly float $maxCostUsd,
        public readonly int   $maxLatencyMs,
        public readonly float $minReviewScore,
    ) {}

    public static function breakingNews(): self
    {
        return new self(
            narrativeWeight:    0.30,
            costWeight:         0.25,
            latencyWeight:      0.35,
            reviewScoreWeight:  0.10,
            maxCostUsd:         1.00,
            maxLatencyMs:       15000,
            minReviewScore:     0.70,
        );
    }

    public static function quality(): self
    {
        return new self(
            narrativeWeight:    0.50,
            costWeight:         0.15,
            latencyWeight:      0.10,
            reviewScoreWeight:  0.25,
            maxCostUsd:         5.00,
            maxLatencyMs:       60000,
            minReviewScore:     0.80,
        );
    }
}
