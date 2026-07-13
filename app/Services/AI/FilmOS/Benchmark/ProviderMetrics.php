<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark;

/**
 * Aggregated statistics for a single provider, computed by BenchmarkAggregator.
 * Intermediate DTO between BenchmarkRepository and ProviderProfileUpdater.
 *
 * successCount / failedCount / timeoutCount default to 0 until BenchmarkRecorder
 * tracks non-successful results (currently only DOWNLOAD_COMPLETED is recorded).
 * Fields are present now so ProviderProfileUpdater can use them in C.7+ without
 * a breaking DTO change.
 */
final class ProviderMetrics
{
    public function __construct(
        public readonly string $provider,
        public readonly float  $avgCost,
        public readonly float  $avgLatency,
        public readonly float  $avgQuality,
        public readonly int    $sampleSize,
        public readonly int    $successCount = 0,
        public readonly int    $failedCount  = 0,
        public readonly int    $timeoutCount = 0,
    ) {}

    public function hasData(): bool
    {
        return $this->sampleSize > 0;
    }

    public function failureRate(): float
    {
        $total = $this->successCount + $this->failedCount + $this->timeoutCount;
        return $total > 0 ? ($this->failedCount + $this->timeoutCount) / $total : 0.0;
    }
}
