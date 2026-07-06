<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * TimelineEvent — pure event node on a temporal track.
 *
 * Carries timing, confidence, origin, priority, and layer — nothing else.
 * Graph structure (who depends on whom) lives in the TemporalGraph's EdgeStore.
 *
 * Round 8C had relations[] embedded on events (adjacency list).
 * Round 9 moves relations to EdgeStore: nodes are pure data, edges are graph state.
 * This allows the optimizer to rewrite edges without reconstructing event objects,
 * and allows EdgeStore to be indexed for O(1) lookup in both directions.
 *
 * Compiler rules:
 *   - $label is NEVER read by serializers or optimizers. Debug / observability only.
 *   - Serializers must build output exclusively from semantic fields on subclasses.
 *   - $confidence (0.0–1.0) lets the Optimizer flag or reweight low-confidence events.
 *   - $origin (EventOrigin enum) records which stage or source produced the event.
 */
abstract class TimelineEvent
{
    public function __construct(
        public readonly string      $id,
        public readonly float       $startSec,
        public readonly float       $endSec,
        public readonly float       $confidence = 1.0,
        public readonly EventOrigin $origin     = EventOrigin::Unknown,
        public readonly int         $priority   = 0,
        public readonly string      $layer      = 'default',
        /** Debug/observability only — must NOT flow into prompt compilation. */
        public readonly ?string     $label      = null,
    ) {}

    public function duration(): float
    {
        return $this->endSec - $this->startSec;
    }
}
