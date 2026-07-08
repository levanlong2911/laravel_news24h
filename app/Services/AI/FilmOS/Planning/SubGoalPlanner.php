<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\Strategies\DecisionStrategy;

/**
 * Cross-votes among strategies for each leaf GoalNode to produce a PlannedShot.
 * Internal to the Planning Layer — not a kernel plugin.
 */
final class SubGoalPlanner
{
    /** @param DecisionStrategy[] $strategies */
    public function __construct(private readonly array $strategies) {}

    public function plan(GoalNode $leaf, MeaningGraph $meaning, array $worldState): PlannedShot
    {
        $merged = [];
        $rationale = [];

        foreach ($this->strategies as $strategy) {
            $candidates = $strategy->candidates($leaf, $meaning, $worldState);
            if (!empty($candidates)) {
                $best = $candidates[0];
                $merged = array_merge($merged, $best->execution);
                $rationale[] = $best->rationale;
            }
        }

        return new PlannedShot(
            position:    0,  // assigned by SequenceOptimizer
            subGoalId:   $leaf->id,
            description: $leaf->description,
            execution:   $merged,
            rationale:   implode('; ', $rationale),
        );
    }
}
