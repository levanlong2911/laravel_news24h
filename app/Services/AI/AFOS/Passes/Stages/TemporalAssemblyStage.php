<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ir\Temporal\CameraTrack;
use App\Services\AI\AFOS\Ir\Temporal\MotionTrack;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * TemporalAssemblyStage — TrackStore → TemporalGraph.
 *
 * Reads all tracks written into TrackStore (MotionTrack, CameraTrack, and any
 * future tracks) and assembles them into a single TemporalGraph that Tier3Stage
 * reads as a clean read-only value.
 *
 * Keeps Tier3Stage free from aggregation responsibility (SRP).
 */
final class TemporalAssemblyStage implements CompilerStage
{
    public function run(PipelineState $state): PipelineState
    {
        $graph = TemporalGraph::fromTracks($state->shot->durationSec, ...$state->tracks->all());
        return $state->withTemporalGraph($graph);
    }

    public function name(): string { return 'TemporalAssemblyStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'TemporalAssemblyStage',
            reads:          [MotionTrack::class, CameraTrack::class, ShotGoalIR::class],
            writes:         [TemporalGraph::class],
            cost:           StageCost::cpu(0.1),
            description:    'TrackStore → TemporalGraph: assembles all temporal tracks into a unified graph for Tier3Stage.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
        );
    }
}
