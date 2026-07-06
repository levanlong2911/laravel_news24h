<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * GraphSnapshot — immutable fingerprint of a FrozenTemporalGraph.
 *
 * Created by TemporalGraph::freeze() at the moment of sealing.
 * Useful for:
 *   - Profiling (benchmark what changed between shots)
 *   - Caching (skip full compilation when snapshotHash matches a previous run)
 *   - Diagnostics (log graph shape alongside compile time)
 *
 * The snapshotHash is a deterministic SHA-1 over the canonical graph structure.
 * Two graphs with the same tracks, edges, and duration produce identical hashes.
 * Round 10: the cache layer can key on snapshotHash to skip recompilation.
 */
final class GraphSnapshot
{
    public function __construct(
        /** Monotonic compile-time identifier — microseconds since epoch. */
        public readonly string $id,
        /** SHA-1 of canonical graph structure (durationSec + sorted trackIds + edge keys). */
        public readonly string $snapshotHash,
        /** Total events across all tracks. */
        public readonly int    $nodeCount,
        /** Total edges in the EdgeStore. */
        public readonly int    $edgeCount,
        /** Number of tracks present. */
        public readonly int    $trackCount,
        /** Wall-clock microseconds when freeze() was called. */
        public readonly float  $frozenAtUs,
    ) {}

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'snapshot_hash' => $this->snapshotHash,
            'node_count'    => $this->nodeCount,
            'edge_count'    => $this->edgeCount,
            'track_count'   => $this->trackCount,
            'frozen_at_us'  => $this->frozenAtUs,
        ];
    }
}
