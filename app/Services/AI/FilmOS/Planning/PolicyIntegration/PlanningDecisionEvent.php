<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\PolicyIntegration;

use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

/**
 * Emitted by PolicyAwarePlanner after every planning cycle.
 * Lets observers (Doctor, metrics, dashboards) see which policies
 * fired and how much they constrained the plan.
 */
final class PlanningDecisionEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly int    $goalCount,
        public readonly int    $shotCount,
        public readonly array  $appliedPolicies,
        public readonly array  $skippedPolicies,
        public readonly int    $originalMaxLatencyMs,
        public readonly int    $constrainedMaxLatencyMs,
        public readonly string $qualityCostBias,
        public readonly bool   $deferExecution,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'planning.decision';
    }

    public function payload(): array
    {
        return [
            'goalCount'               => $this->goalCount,
            'shotCount'               => $this->shotCount,
            'appliedPolicies'         => $this->appliedPolicies,
            'skippedPolicies'         => $this->skippedPolicies,
            'originalMaxLatencyMs'    => $this->originalMaxLatencyMs,
            'constrainedMaxLatencyMs' => $this->constrainedMaxLatencyMs,
            'qualityCostBias'         => $this->qualityCostBias,
            'deferExecution'          => $this->deferExecution,
            'latencyConstrained'      => $this->constrainedMaxLatencyMs < $this->originalMaxLatencyMs,
        ];
    }

    /**
     * Structural fields for deterministic hashing. The payload is already
     * deterministic here — counts, policy names, plan-constraint budgets and
     * booleans, with no executionId, timestamp, or runtime cost — so it doubles
     * as the canonical data.
     */
    public function canonicalData(): array
    {
        return $this->payload();
    }
}
