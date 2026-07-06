<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Motion;

use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;

/**
 * MotionPlanner — contract for planning a MotionPlan from a ShotGoalIR.
 *
 * Returns a MotionPlan (track + edges) rather than a bare MotionTrack.
 * This enforces the separation: event nodes belong to the track, graph edges
 * belong to the TemporalGraph's EdgeStore — a planner must produce both.
 *
 * MotionBeatStage depends on this interface, not on a concrete implementation.
 * Swapping planning strategies requires zero changes to the stage.
 *
 * Current implementation: RuleBasedMotionPlanner (goal-type lookup tables).
 * Round 10 addition: LLMMotionPlanner (language-model-guided beat generation).
 * Round 12 addition: GrammarMotionPlanner (MotionGrammar formal grammar).
 */
interface MotionPlanner
{
    public function plan(ShotGoalIR $goal, CompositionIR $composition): MotionPlan;
}
