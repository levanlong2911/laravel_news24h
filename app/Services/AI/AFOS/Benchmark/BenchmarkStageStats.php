<?php

namespace App\Services\AI\AFOS\Benchmark;

use App\Services\AI\AFOS\Passes\Pipeline\StageProfile;

/**
 * BenchmarkStageStats — per-stage percentile statistics across multiple compile runs.
 *
 * Aggregates StageProfile measurements from N shots, then computes
 * P50 / P90 / P95 / P99 latency for each stage.
 *
 * Averages miss spikes (P99 Tier2=220ms vs avg=4ms); percentiles don't.
 *
 * Usage:
 *   $stats = new BenchmarkStageStats();
 *   foreach ($snapshots as $s) { $stats->recordAll($s->profiles); }
 *   $summary['stage_percentiles'] = $stats->describeAll();
 */
final class BenchmarkStageStats
{
    /** @var array<string, float[]> stageName → durationMs[] */
    private array $measurements = [];

    /** @param StageProfile[] $profiles */
    public function recordAll(array $profiles): void
    {
        foreach ($profiles as $profile) {
            $this->measurements[$profile->stageName][] = $profile->durationMs;
        }
    }

    /**
     * Per-stage statistics: avg, min, max, P50, P90, P95, P99.
     *
     * @return array<string, array<string, float>>
     */
    public function describeAll(): array
    {
        $result = [];
        foreach (array_keys($this->measurements) as $stageName) {
            $result[$stageName] = $this->describe($stageName);
        }
        return $result;
    }

    /** Statistics for a single stage name. */
    public function describe(string $stageName): array
    {
        $values = $this->measurements[$stageName] ?? [];
        if (empty($values)) {
            return [];
        }

        sort($values);
        $count = count($values);

        return [
            'count' => $count,
            'avg'   => round(array_sum($values) / $count, 3),
            'min'   => round($values[0], 3),
            'max'   => round($values[$count - 1], 3),
            'p50'   => round($this->percentileFromSorted($values, 50), 3),
            'p90'   => round($this->percentileFromSorted($values, 90), 3),
            'p95'   => round($this->percentileFromSorted($values, 95), 3),
            'p99'   => round($this->percentileFromSorted($values, 99), 3),
        ];
    }

    /** Nearest-rank percentile on a pre-sorted array. */
    private function percentileFromSorted(array $sorted, int $p): float
    {
        $count = count($sorted);
        if ($count === 1) {
            return $sorted[0];
        }
        $idx = (int) ceil($p / 100.0 * $count) - 1;
        return $sorted[max(0, min($idx, $count - 1))];
    }
}
