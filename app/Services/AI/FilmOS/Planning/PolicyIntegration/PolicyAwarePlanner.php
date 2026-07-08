<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\PolicyIntegration;

use App\Services\AI\FilmOS\EventBus\EventBus;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\FilmPlanner;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\PlanObjectives;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;
use App\Services\AI\FilmOS\Policy\PolicyContext;
use App\Services\AI\FilmOS\Policy\PolicyEngine;

/**
 * Decorator that inserts PolicyEngine into the planning loop.
 *
 * Before:
 *   Planner → Plan → Execution
 *
 * After:
 *   Goal
 *    ↓
 *   PolicyAwarePlanner
 *    ↓ builds PolicyContext from worldState
 *   PolicyEngine.decide()
 *    ↓ PolicyDecision
 *   PolicyToPlanAdapter.adapt()
 *    ↓ constrained PlanObjectives
 *   inner FilmPlanner.plan()
 *    ↓ ShotSequencePlan (policy-constrained)
 *   MultiObjectiveOptimizer (uses same constrained objectives)
 *    ↓
 *   Execution
 *
 * Policy is not a post-execution check — it shapes what the planner
 * is even allowed to produce. The inner planner never sees unconstrained
 * objectives; it only sees what policy has approved.
 *
 * worldState keys consumed by PolicyEngine (examples):
 *   content_type          → 'breaking_news' | 'documentary'
 *   customer_tier         → 'premium' | 'standard' | 'budget'
 *   budget_remaining_usd  → float
 *   reviewer_confidence   → float 0.0–1.0
 *   gpu_cluster.temp_c    → float
 */
final class PolicyAwarePlanner implements FilmPlanner
{
    public function __construct(
        private readonly FilmPlanner        $inner,
        private readonly PolicyEngine       $policyEngine,
        private readonly PolicyToPlanAdapter $adapter,
        private readonly ?EventBus          $eventBus = null,
    ) {}

    public function plan(
        GoalGraph      $goals,
        MeaningGraph   $meaning,
        array          $worldState,
        PlanObjectives $objectives,
    ): ShotSequencePlan {
        $context  = PolicyContext::from($worldState);
        $decision = $this->policyEngine->decide($context);

        $constrained = $this->adapter->adapt($decision, $objectives);

        $plan = $this->inner->plan($goals, $meaning, $worldState, $constrained);
        $plan = $plan->withPolicyDecision($decision);

        if ($this->eventBus !== null) {
            $this->eventBus->dispatch(new PlanningDecisionEvent(
                goalCount:       count($goals->nodes()),
                shotCount:       count($plan->shots),
                appliedPolicies: $decision->appliedPolicies,
                skippedPolicies: $decision->skippedPolicies,
                originalMaxLatencyMs:    $objectives->maxLatencyMs,
                constrainedMaxLatencyMs: $constrained->maxLatencyMs,
                qualityCostBias:         $decision->qualityCostBias,
                deferExecution:          $decision->deferExecution,
            ));
        }

        return $plan;
    }
}
