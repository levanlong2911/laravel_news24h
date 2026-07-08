<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\PolicyIntegration;

use App\Services\AI\FilmOS\Planning\PlanObjectives;
use App\Services\AI\FilmOS\Policy\PolicyDecision;

/**
 * Bridge between the Policy layer and the Planning layer.
 *
 * Translates a PolicyDecision (governance language) into a constrained
 * PlanObjectives (optimiser language) so the MultiObjectiveOptimizer
 * operates within policy-mandated bounds without knowing anything about
 * policy rules or providers.
 *
 * Rules:
 *   qualityCostBias  → objective weight distribution
 *   maxLatencyMs     → takes the STRICTER of policy and base (min)
 *   requiredReviewers > 1 → raises minReviewScore (+5pp per extra reviewer, capped at 0.95)
 *   deferExecution   → relaxes maxLatencyMs (no hard latency pressure when deferred)
 */
final class PolicyToPlanAdapter
{
    public function adapt(PolicyDecision $decision, PlanObjectives $base): PlanObjectives
    {
        [$narrativeW, $costW, $latencyW, $reviewW] = $this->weightsFromBias($decision->qualityCostBias);

        $maxLatencyMs = $this->resolveLatency($decision, $base->maxLatencyMs);

        $minReviewScore = $decision->requiredReviewers > 1
            ? min(0.95, $base->minReviewScore + ($decision->requiredReviewers - 1) * 0.05)
            : $base->minReviewScore;

        return new PlanObjectives(
            narrativeWeight:   $narrativeW,
            costWeight:        $costW,
            latencyWeight:     $latencyW,
            reviewScoreWeight: $reviewW,
            maxCostUsd:        $base->maxCostUsd,
            maxLatencyMs:      $maxLatencyMs,
            minReviewScore:    $minReviewScore,
        );
    }

    /** @return array{float, float, float, float} [narrative, cost, latency, review] */
    private function weightsFromBias(string $bias): array
    {
        return match ($bias) {
            'quality'  => [0.50, 0.10, 0.15, 0.25],
            'cost'     => [0.25, 0.45, 0.20, 0.10],
            'balanced' => [0.35, 0.25, 0.25, 0.15],
            default    => [0.35, 0.25, 0.25, 0.15],
        };
    }

    private function resolveLatency(PolicyDecision $decision, int $baseMs): int
    {
        if ($decision->deferExecution) {
            // Deferred execution: relax latency constraint entirely.
            return PHP_INT_MAX;
        }
        if ($decision->hasLatencyConstraint()) {
            return min($baseMs, (int) $decision->maxLatencyMs);
        }
        return $baseMs;
    }
}
