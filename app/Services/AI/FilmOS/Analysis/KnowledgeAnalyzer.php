<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Analysis;

/**
 * Precomputes the BenchmarkReport from joined outcomes.
 *
 * Single responsibility (C.8A pipeline):
 *   KnowledgeExtractor → ShotKnowledge[] → OutcomeJoiner → ShotOutcome[] → HERE → BenchmarkReport
 *
 * Knows NOTHING about View interfaces AND nothing about BenchmarkResult —
 * its only input is ShotOutcome[] from OutcomeJoiner.
 *
 * Analyzer = knowledge, not decision: aggregates carry averages and sample
 * sizes only. No thresholds, no recommendations, no weight changes.
 */
final class KnowledgeAnalyzer
{
    /** @param ShotOutcome[] $outcomes */
    public function analyze(array $outcomes): BenchmarkReport
    {
        $outcomes = array_values($outcomes);

        return new BenchmarkReport(
            qualityByBeat:        $this->aggregate($outcomes, fn(ShotOutcome $o) => $o->knowledge->beat !== null ? [$o->knowledge->beat->value] : []),
            qualityByShotType:    $this->aggregate($outcomes, fn(ShotOutcome $o) => $o->knowledge->camera !== null ? [$o->knowledge->camera->shotType->value] : []),
            qualityByLens:        $this->aggregate($outcomes, fn(ShotOutcome $o) => $o->knowledge->camera !== null ? [$o->knowledge->camera->lens->value] : []),
            qualityByFindingCode: $this->aggregate($outcomes, fn(ShotOutcome $o) => $o->knowledge->findingCodes),
            outcomes:             $outcomes,
        );
    }

    /**
     * Groups outcomes by the keys a selector yields (0..n keys per outcome)
     * and precomputes avgQuality + sampleSize per key.
     *
     * @param  ShotOutcome[]                   $outcomes
     * @param  callable(ShotOutcome): string[] $keysOf
     * @return array<string, array{avgQuality: float, sampleSize: int}>
     */
    private function aggregate(array $outcomes, callable $keysOf): array
    {
        $sums   = [];
        $counts = [];

        foreach ($outcomes as $outcome) {
            foreach ($keysOf($outcome) as $key) {
                $sums[$key]   = ($sums[$key]   ?? 0.0) + $outcome->quality;
                $counts[$key] = ($counts[$key] ?? 0)   + 1;
            }
        }

        $result = [];
        foreach ($sums as $key => $sum) {
            $result[$key] = [
                'avgQuality' => $sum / $counts[$key],
                'sampleSize' => $counts[$key],
            ];
        }

        return $result;
    }
}
