<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Learning;

final class PredictionResult
{
    public function __construct(
        public readonly float  $expectedCtr,
        public readonly float  $expectedWatchTime,
        public readonly float  $expectedReviewScore,
        public readonly float  $confidence,
        public readonly int    $comparableProductions,
        public readonly string $basedOn,
        public readonly bool   $hasPrior = true,
    ) {}

    public function isReliable(float $minConfidence = 0.70, int $minSamples = 20): bool
    {
        return $this->hasPrior
            && $this->confidence >= $minConfidence
            && $this->comparableProductions >= $minSamples;
    }

    public static function noPrior(string $reason = ''): self
    {
        return new self(
            expectedCtr:            0.0,
            expectedWatchTime:      0.0,
            expectedReviewScore:    0.0,
            confidence:             0.0,
            comparableProductions:  0,
            basedOn:                $reason,
            hasPrior:               false,
        );
    }
}
