<?php

namespace App\Services\AI\AFOS\Passes\Graph;

use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;

/**
 * GraphPass — transformation or analysis pass over a TemporalGraph.
 *
 * The TemporalGraph flows through a sequence of GraphPasses after FreezeStage.
 * Each pass receives the full graph and returns a (possibly modified) graph.
 *
 * Design:
 *   - Passes are pure functions: same graph in → same graph out, no side effects.
 *   - Analysis passes return the graph unchanged but emit OptimizationSuggestion[].
 *   - Transform passes apply suggestions and return a new graph.
 *
 * Round 9 passes:
 *   GraphValidationPass    — re-validates the frozen graph before optimization
 *   GraphOptimizerPass     — emits OptimizationSuggestion[] (analysis only)
 *   ApplySuggestionsPass   — applies OptimizationSuggestion[] via SuggestionExecutor
 *
 * Future passes (Round 10+):
 *   DeadBeatEliminationPass  — remove beats with confidence < threshold
 *   BeatFusionPass           — merge adjacent beats with same actor/channel
 *   CrossTrackConstraintPass — validate cross-track temporal constraints
 */
interface GraphPass
{
    public function apply(TemporalGraph $graph): TemporalGraph;

    /** Stable identifier used for logging and benchmark tooling. */
    public function name(): string;
}
