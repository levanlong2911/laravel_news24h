<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Policy\PolicyDecision;

final class ShotSequencePlan
{
    public function __construct(
        public readonly string          $planId,
        public readonly GoalGraph       $goalGraph,
        /** @var PlannedShot[] */
        public readonly array           $shots,
        public readonly float           $goalConfidence,
        public readonly ?PlanScore      $score = null,
        /** Governance decision attached by PolicyAwarePlanner — single source of truth for downstream layers. */
        public readonly ?PolicyDecision $policyDecision = null,
    ) {}

    /** Return a copy of this plan with the given PolicyDecision attached. */
    public function withPolicyDecision(PolicyDecision $decision): self
    {
        return new self(
            $this->planId,
            $this->goalGraph,
            $this->shots,
            $this->goalConfidence,
            $this->score,
            $decision,
        );
    }

    public function meetsHardCaps(PlanObjectives $objectives): bool
    {
        return $this->score !== null && $this->score->meetsHardCaps($objectives);
    }
}
