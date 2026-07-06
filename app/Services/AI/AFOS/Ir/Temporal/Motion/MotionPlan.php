<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Motion;

use App\Services\AI\AFOS\Ir\Temporal\EventEdge;
use App\Services\AI\AFOS\Ir\Temporal\MotionTrack;

/**
 * MotionPlan — the return value of MotionPlanner::plan().
 *
 * Bundles the MotionTrack (event nodes) with the EventEdge[] (graph edges) so
 * that MotionBeatStage can wire both into the TemporalGraph in a single step.
 *
 * MotionTrack carries only pure event nodes (id, timing, semantic fields).
 * EventEdge[] carries the graph structure (who depends on whom and how).
 *
 * The separation enforces the invariant: nodes live on tracks, edges live in EdgeStore.
 */
final class MotionPlan
{
    /**
     * @param MotionTrack  $track  Pure event nodes — no embedded relation data.
     * @param EventEdge[]  $edges  Edges to be added to the TemporalGraph's EdgeStore.
     */
    public function __construct(
        public readonly MotionTrack $track,
        public readonly array       $edges,
    ) {}
}
