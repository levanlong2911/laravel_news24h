<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Analysis;

/**
 * STABLE CONTRACT — the knowledge product of C.8A, consumed by C.8B
 * (future NodeWeightProviders) and humans comparing runs against baseline.
 *
 * INVARIANT: fully immutable AND fully precomputed. KnowledgeAnalyzer builds
 * every aggregate before construction; this class only reads — no lazy
 * computation, no caching, no state (same rule as NarrativeAuditReport).
 *
 * Aggregate entry shape (all four dimensions):
 *   [dimensionValue => ['avgQuality' => float, 'sampleSize' => int]]
 *
 * The report describes correlations — it never recommends. Deciding what to
 * do with a correlation is C.8B / human territory.
 */
final class BenchmarkReport
{
    /**
     * @param array<string, array{avgQuality: float, sampleSize: int}> $qualityByBeat
     * @param array<string, array{avgQuality: float, sampleSize: int}> $qualityByShotType
     * @param array<string, array{avgQuality: float, sampleSize: int}> $qualityByLens
     * @param array<string, array{avgQuality: float, sampleSize: int}> $qualityByFindingCode
     * @param ShotOutcome[]                                            $outcomes
     */
    public function __construct(
        private readonly array $qualityByBeat,
        private readonly array $qualityByShotType,
        private readonly array $qualityByLens,
        private readonly array $qualityByFindingCode,
        private readonly array $outcomes,
    ) {}

    /** @return array<string, array{avgQuality: float, sampleSize: int}> keyed by StoryBeat value */
    public function qualityByBeat(): array
    {
        return $this->qualityByBeat;
    }

    /** @return array<string, array{avgQuality: float, sampleSize: int}> keyed by ShotType value */
    public function qualityByShotType(): array
    {
        return $this->qualityByShotType;
    }

    /** @return array<string, array{avgQuality: float, sampleSize: int}> keyed by LensType value */
    public function qualityByLens(): array
    {
        return $this->qualityByLens;
    }

    /** @return array<string, array{avgQuality: float, sampleSize: int}> keyed by QA finding code */
    public function qualityByFindingCode(): array
    {
        return $this->qualityByFindingCode;
    }

    /** @return ShotOutcome[] raw joined outcomes — C.8B's input */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /** Number of shots that had BOTH knowledge and a benchmark result. */
    public function sampleSize(): int
    {
        return count($this->outcomes);
    }
}
