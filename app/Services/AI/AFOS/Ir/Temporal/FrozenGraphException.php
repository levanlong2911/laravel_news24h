<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * FrozenGraphException — thrown when a write is attempted on a frozen TemporalGraph.
 *
 * Thrown by TemporalGraph::withTrack(), withEdge(), and withEdges() after freeze().
 * Catching this type (rather than \LogicException) lets the optimizer and diagnostics
 * layer distinguish pipeline-ordering bugs from other logic errors.
 *
 * Root cause is always: a stage attempted to mutate the graph after FreezeStage ran.
 */
final class FrozenGraphException extends \LogicException {}
