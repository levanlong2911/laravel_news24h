<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Analysis;

use App\Services\AI\FilmOS\Benchmark\BenchmarkResult;

/**
 * The middle tier of C.8A — the ONLY class that sees both worlds:
 *
 *   KnowledgeExtractor → ShotKnowledge[]   (knowledge, no benchmark)
 *   OutcomeJoiner      → ShotOutcome[]     (join lives HERE, single place)
 *   KnowledgeAnalyzer  → BenchmarkReport   (aggregates, no Views, no BenchmarkResult)
 *
 * JOIN KEY (frozen with C.8A, phương án B):
 *   ShotKnowledge::$ordinal == BenchmarkResult::$ordinal
 * Ordinal is the shot identity frozen in D1. goalId is a business identifier
 * (renamable, e.g. shot_hook → opening_hook) kept for trace/debug only —
 * never used to join.
 *
 * Skipped without error:
 *   - results with ordinal === null  (pre-C.8A legacy rows — unjoinable)
 *   - results whose ordinal has no knowledge (shot not planned in this narrative)
 *
 * Outcome metrics are flattened (quality/latency/cost), never the whole
 * BenchmarkResult.
 */
final class OutcomeJoiner
{
    /**
     * @param  array<int, ShotKnowledge> $knowledge keyed by ordinal
     * @param  iterable<BenchmarkResult> $results
     * @return ShotOutcome[]
     */
    public function join(array $knowledge, iterable $results): array
    {
        $outcomes = [];

        foreach ($results as $result) {
            if ($result->ordinal === null) {
                continue;  // legacy row — no join identity
            }

            $shot = $knowledge[$result->ordinal] ?? null;
            if ($shot === null) {
                continue;  // measures a shot this narrative never planned
            }

            $outcomes[] = new ShotOutcome(
                knowledge:      $shot,
                quality:        $result->qualityScore,
                latencySeconds: $result->latencySeconds,
                cost:           $result->cost,
            );
        }

        return $outcomes;
    }
}
