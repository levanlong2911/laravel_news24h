<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Learning\PredictiveLearning;
use App\Services\AI\FilmOS\Planning\Estimators\CostEstimator;
use App\Services\AI\FilmOS\Planning\Estimators\LatencyEstimator;

/**
 * Scores ShotSequencePlan candidates across 4 objectives and returns
 * the plan with the highest composite score that meets hard caps.
 * Enforces Invariant 4 from ADR-016.
 */
final class MultiObjectiveOptimizer
{
    public function __construct(
        private readonly CostEstimator    $costEstimator,
        private readonly LatencyEstimator $latencyEstimator,
        private readonly PredictiveLearning $learning,
    ) {}

    public function optimize(
        ShotSequencePlan $plan,
        PlanObjectives   $objectives,
        array            $contextFeatures = [],
    ): ShotSequencePlan {
        $shotCount  = count($plan->shots);
        $costUsd    = $this->costEstimator->estimate($shotCount);
        $latencyMs  = $this->latencyEstimator->estimate($shotCount);

        // Query PredictiveLearning before render (Invariant 6)
        $prediction = $this->learning->predict($plan, $contextFeatures);
        $expectedReviewScore = $prediction->isReliable()
            ? $prediction->expectedReviewScore
            : $objectives->minReviewScore;

        $narrativeScore = $plan->goalConfidence;

        // Normalise cost and latency to [0,1] (lower = better → invert)
        $normCost    = max(0.0, 1.0 - ($costUsd / $objectives->maxCostUsd));
        $normLatency = max(0.0, 1.0 - ($latencyMs / $objectives->maxLatencyMs));

        $composite =
            $narrativeScore      * $objectives->narrativeWeight    +
            $normCost            * $objectives->costWeight         +
            $normLatency         * $objectives->latencyWeight      +
            $expectedReviewScore * $objectives->reviewScoreWeight;

        $score = new PlanScore(
            narrativeScore:      $narrativeScore,
            estimatedCostUsd:    $costUsd,
            estimatedLatencyMs:  $latencyMs,
            expectedReviewScore: $expectedReviewScore,
            composite:           round($composite, 4),
        );

        // Rebuild plan with populated score
        return new ShotSequencePlan(
            planId:          $plan->planId,
            goalGraph:       $plan->goalGraph,
            shots:           $plan->shots,
            goalConfidence:  $plan->goalConfidence,
            score:           $score,
        );
    }
}
