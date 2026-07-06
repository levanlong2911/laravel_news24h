<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Ir\Temporal\FrozenTemporalGraph;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * FreezeStage — seals the mutable TemporalGraph into a FrozenTemporalGraph.
 *
 * This is the BUILD→FREEZE boundary in the compiler lifecycle.
 * After this stage, all downstream stages receive a FrozenTemporalGraph:
 *   - Type-level immutability (no withTrack/withEdge methods exist)
 *   - validate + canonicalize already run (Tier3Stage doesn't re-validate)
 *   - GraphSnapshot available for profiling and cache keying (Round 10)
 *
 * Lifecycle:
 *   Before: Stages → TemporalGraph (mutable, $state->graph)
 *   After:  Stages → FrozenTemporalGraph (sealed, $state->frozenGraph)
 */
final class FreezeStage implements CompilerStage
{
    public function run(PipelineState $state): PipelineState
    {
        $graph = $state->graph ?? TemporalGraph::empty($state->shot->durationSec);
        return $state->sealed($graph->freeze());
    }

    public function name(): string { return 'FreezeStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'FreezeStage',
            reads:          [TemporalGraph::class],
            writes:         [FrozenTemporalGraph::class],
            cost:           StageCost::constant(0.05),
            description:    'Seals the mutable TemporalGraph into a FrozenTemporalGraph — validates, canonicalizes, and snapshots before Optimizer and Serializer stages.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'barrier',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR, StageCapability::FREEZE],
            phase:          CompilerPhase::FREEZE,
        );
    }
}
