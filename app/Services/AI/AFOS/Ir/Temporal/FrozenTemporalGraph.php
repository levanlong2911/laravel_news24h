<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\Validation\TimelineValidationResult;

/**
 * FrozenTemporalGraph — the sealed, read-only product of TemporalGraph::freeze().
 *
 * Type-level immutability: there are no withTrack() / withEdge() methods.
 * The compiler guarantees at the type system level that no stage after FreezeStage
 * can mutate the graph — not by convention, not by runtime flag, but by type.
 *
 * Produced by: FreezeStage via TemporalGraph::freeze()
 * Consumed by: Tier3Stage, BackendStage, Optimizer passes (Round 10)
 *
 * Lifecycle:
 *   TemporalGraph (mutable builder, BUILD phase)
 *   → freeze()                          ← validate + canonicalize + snapshot
 *   → FrozenTemporalGraph (sealed)      ← OPTIMIZE / LOWER / EMIT phases
 *
 * The snapshot() method provides a deterministic fingerprint for profiling
 * and caching (Round 10 cache layer can key on snapshotHash).
 */
final class FrozenTemporalGraph implements TemporalGraphView
{
    /**
     * @param array<string, TimelineTrack> $tracks   Canonically sorted by TrackId.
     * @param EdgeStore                    $edges    Canonically sorted EdgeStore.
     */
    public function __construct(
        public readonly float                  $durationSec,
        private readonly array                 $tracks,
        private readonly EdgeStore             $edges,
        private readonly TimelineValidationResult $validationResult,
        private readonly GraphSnapshot         $snapshot,
    ) {}

    // ── Typed track accessors ─────────────────────────────────────────────────

    public function motion(): ?MotionTrack
    {
        return $this->tracks[MotionTrack::ID] ?? null;
    }

    public function camera(): ?CameraTrack
    {
        return $this->tracks[CameraTrack::ID] ?? null;
    }

    // ── Generic track access ──────────────────────────────────────────────────

    public function get(string $trackId): ?TimelineTrack
    {
        return $this->tracks[$trackId] ?? null;
    }

    public function has(string $trackId): bool
    {
        return isset($this->tracks[$trackId]);
    }

    /** @return TimelineTrack[] All tracks in canonical (alphabetical TrackId) order. */
    public function all(): array
    {
        return array_values($this->tracks);
    }

    /** @return string[] Domain TrackIds in canonical order. */
    public function trackIds(): array
    {
        return array_keys($this->tracks);
    }

    public function trackCount(): int
    {
        return count($this->tracks);
    }

    public function isEmpty(): bool
    {
        return $this->tracks === [];
    }

    // ── EdgeStore access ──────────────────────────────────────────────────────

    /** The canonically sorted EdgeStore — deterministic across runs with the same input. */
    public function edges(): EdgeStore
    {
        return $this->edges;
    }

    // ── Graph-level operations ────────────────────────────────────────────────

    /**
     * Finds an event by ID across all tracks.
     * O(n) — Phase 5 Query API will provide O(1) indexed lookup.
     */
    public function findEvent(string $id): ?TimelineEvent
    {
        foreach ($this->tracks as $track) {
            foreach ($track->orderedEvents() as $event) {
                if ($event->id === $id) {
                    return $event;
                }
            }
        }
        return null;
    }

    // ── Freeze-time artifacts ─────────────────────────────────────────────────

    /**
     * Validation result computed at freeze() time over the fully built graph.
     * Stages downstream of FreezeStage read this rather than re-validating.
     */
    public function validationResult(): TimelineValidationResult
    {
        return $this->validationResult;
    }

    /**
     * Deterministic fingerprint: hash, node/edge/track counts, timestamp.
     * Round 10 cache layer keys on snapshotHash to skip recompilation.
     */
    public function snapshot(): GraphSnapshot
    {
        return $this->snapshot;
    }
}
