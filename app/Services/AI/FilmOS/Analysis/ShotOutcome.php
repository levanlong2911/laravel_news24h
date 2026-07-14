<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Analysis;

/**
 * "We decided X, we measured Y" — one shot's knowledge joined with its outcome.
 *
 * Outcome metrics are FLATTENED on purpose: analysis needs quality/latency/cost,
 * not the whole BenchmarkResult — pulling the full result object into the
 * analysis domain would couple C.8A to every future BenchmarkResult field.
 *
 * Immutable.
 */
final class ShotOutcome
{
    public function __construct(
        public readonly ShotKnowledge $knowledge,
        public readonly float         $quality,
        public readonly float         $latencySeconds,
        public readonly float         $cost,
    ) {}
}
