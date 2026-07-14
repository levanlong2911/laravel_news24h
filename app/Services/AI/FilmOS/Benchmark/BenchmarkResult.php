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
        /** Shot ordinal — the JOIN IDENTITY for C.8A knowledge analysis.
         *  goalId is a business identifier (renamable) kept for trace/debug.
         *  Nullable additive field: pre-C.8A results remain valid but cannot
         *  be joined to narrative knowledge. */
        public readonly ?int   $ordinal = null,
    ) {}
}
