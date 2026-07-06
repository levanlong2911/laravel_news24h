<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Ir\Temporal\Motion\MotionPlanner;
use App\Services\AI\AFOS\Ir\Temporal\MotionTrack;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * MotionBeatStage — ShotGoalIR + CompositionIR → MotionTrack + edges in TemporalGraph.
 *
 * Delegates all beat/intent generation to the injected MotionPlanner.
 * The stage itself knows nothing about beats — it is a pipeline adapter only.
 *
 * Swap MotionPlanner implementations to change planning strategy without
 * touching any pipeline code. Current: RuleBasedMotionPlanner.
 * Round 10: LLMMotionPlanner, GrammarMotionPlanner.
 */
final class MotionBeatStage implements CompilerStage
{
    public function __construct(private readonly MotionPlanner $planner) {}

    public function run(PipelineState $state): PipelineState
    {
        $plan  = $this->planner->plan($state->shot, $state->composition);
        $graph = ($state->graph ?? TemporalGraph::empty($state->shot->durationSec))
            ->withTrack(MotionTrack::ID, $plan->track)
            ->withEdges(...$plan->edges);

        return $state->withGraph($graph);
    }

    public function name(): string { return 'MotionBeatStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'MotionBeatStage',
            reads:          [ShotGoalIR::class, CompositionIR::class],
            writes:         [TemporalGraph::class],
            cost:           StageCost::cpu(1.5),
            description:    'ShotGoalIR + CompositionIR → MotionTrack: delegates to MotionPlanner to generate time-coded biomechanical beats and MotionIntent.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: true,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
            phase:          CompilerPhase::BUILD,
        );
    }
}
