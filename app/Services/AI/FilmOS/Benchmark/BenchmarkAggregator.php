<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark;

/**
 * Aggregates a stream of BenchmarkResults into ProviderMetrics.
 *
 * Stateless. Accepts iterable<BenchmarkResult> so it works with both
 * in-memory arrays and lazy DB cursors without loading everything into RAM.
 */
final class BenchmarkAggregator
{
    /** @param iterable<BenchmarkResult> $results */
    public function aggregate(string $provider, iterable $results): ProviderMetrics
    {
        $totalCost    = 0.0;
        $totalLatency = 0.0;
        $totalQuality = 0.0;
        $count        = 0;

        foreach ($results as $result) {
            $totalCost    += $result->cost;
            $totalLatency += $result->latencySeconds;
            $totalQuality += $result->qualityScore;
            $count++;
        }

        if ($count === 0) {
            return new ProviderMetrics(
                provider:   $provider,
                avgCost:    0.0,
                avgLatency: 0.0,
                avgQuality: 0.0,
                sampleSize: 0,
            );
        }

        return new ProviderMetrics(
            provider:   $provider,
            avgCost:    $totalCost    / $count,
            avgLatency: $totalLatency / $count,
            avgQuality: $totalQuality / $count,
            sampleSize: $count,
        );
    }
}
