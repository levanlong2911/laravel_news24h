<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark;

final class BenchmarkResult
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $provider,
        public readonly string $plannerName,
        public readonly string $goalId,
        public readonly float  $score,
        public readonly float  $roi,
        public readonly float  $cost,
        public readonly float  $latencySeconds,
        public readonly float  $qualityScore,
        public readonly array  $attributes = [],
    ) {}
}
