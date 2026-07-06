<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\Validation\TimelineValidationResult;
use App\Services\AI\AFOS\Ir\Temporal\Validation\TrackValidator;

/**
 * TemporalGraph — the mutable IR builder for all temporal data in a shot.
 *
 * Architecture (Round 9, updated Round 9.5):
 *   - Always mutable: withTrack() / withEdge() / withEdges() never throw.
 *   - freeze() validates + canonicalizes + seals into a FrozenTemporalGraph.
 *     After freeze(), the type system prevents further mutation — no runtime flag.
 *   - EdgeStore owns all EventEdge instances; events are pure data nodes.
 *
 * Lifecycle:
 *   TemporalGraph::empty(durationSec)
 *   → withTrack(MotionTrack::ID, $motionTrack)    [MotionBeatStage — BUILD]
 *   → withTrack(CameraTrack::ID, $cameraTrack)    [CameraArcStage  — BUILD]
 *   → freeze()                                     [FreezeStage     — FREEZE]
 *   → FrozenTemporalGraph passed to Tier3Stage     [LOWER]
 */
final class TemporalGraph
{
    /**
     * @param array<string, TimelineTrack> $tracks  TrackId → track (insertion order)
     * @param EdgeStore                    $edgeStore  All edges in the graph
     */
    private function __construct(
        public readonly float $durationSec,
        private readonly array $tracks,
        private readonly EdgeStore $edgeStore,
    ) {}

    // ── Factories ─────────────────────────────────────────────────────────────

    public static function empty(float $durationSec): self
    {
        return new self($durationSec, [], EdgeStore::empty());
    }

    /**
     * Migration factory — wraps the old variadic constructor API.
     * Used by TemporalAssembler and legacy tests.
     */
    public static function fromTracks(float $durationSec, TimelineTrack ...$tracks): self
    {
        $graph = self::empty($durationSec);
        foreach ($tracks as $track) {
            $graph = $graph->withTrack(self::trackIdFor($track), $track);
        }
        return $graph;
    }

    // ── Builder (immutable mutations) ─────────────────────────────────────────

    /** Returns a new graph with the track added under $trackId. */
    public function withTrack(string $trackId, TimelineTrack $track): self
    {
        $indexed           = $this->tracks;
        $indexed[$trackId] = $track;
        return new self($this->durationSec, $indexed, $this->edgeStore);
    }

    /** Returns a new graph with $edge added to the EdgeStore. */
    public function withEdge(EventEdge $edge): self
    {
        return new self($this->durationSec, $this->tracks, $this->edgeStore->add($edge));
    }

    /** Returns a new graph with multiple edges added. */
    public function withEdges(EventEdge ...$edges): self
    {
        return new self($this->durationSec, $this->tracks, $this->edgeStore->addAll(...$edges));
    }

    // ── Freeze (BUILD phase → FREEZE phase) ───────────────────────────────────

    /**
     * Validate + canonicalize + seal into a FrozenTemporalGraph.
     *
     * Steps:
     *   1. Validate all tracks via TrackValidator (errors recorded, not thrown).
     *   2. Canonicalize: sort tracks by TrackId, sort edges by from|to|type key.
     *   3. Compute GraphSnapshot (deterministic SHA-1 fingerprint).
     *   4. Return FrozenTemporalGraph — type-level immutability, no bool flag.
     */
    public function freeze(): FrozenTemporalGraph
    {
        // 1. Validate
        $validation = $this->validate();

        // 2. Canonicalize: sort tracks by TrackId alphabetically
        $sortedTracks = $this->tracks;
        ksort($sortedTracks);

        // Canonicalize EdgeStore: sort edges by from|to|type for deterministic hashing
        $sortedEdges = $this->edgeStore->sorted();

        // 3. Snapshot
        $snapshot = $this->buildSnapshot($sortedTracks, $sortedEdges);

        // 4. Seal
        return new FrozenTemporalGraph(
            $this->durationSec,
            $sortedTracks,
            $sortedEdges,
            $validation,
            $snapshot,
        );
    }

    // ── Read-only accessors (available during BUILD phase) ────────────────────

    public function motion(): ?MotionTrack
    {
        return $this->tracks[MotionTrack::ID] ?? null;
    }

    public function camera(): ?CameraTrack
    {
        return $this->tracks[CameraTrack::ID] ?? null;
    }

    public function get(string $trackId): ?TimelineTrack
    {
        return $this->tracks[$trackId] ?? null;
    }

    public function has(string $trackId): bool
    {
        return isset($this->tracks[$trackId]);
    }

    /** @return TimelineTrack[] All tracks in insertion order. */
    public function all(): array
    {
        return array_values($this->tracks);
    }

    /** @return string[] Domain TrackIds in insertion order. */
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

    public function edges(): EdgeStore
    {
        return $this->edgeStore;
    }

    /**
     * Finds an event by ID across all tracks.
     * O(n) — Phase 5 Query API will provide indexed lookup.
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

    public function validate(): TimelineValidationResult
    {
        $result = TimelineValidationResult::ok();
        foreach ($this->tracks as $track) {
            $result = $result->merge(TrackValidator::validate($track->orderedEvents(), $this->edgeStore));
        }
        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildSnapshot(array $sortedTracks, EdgeStore $sortedEdges): GraphSnapshot
    {
        $nodeCount = 0;
        $trackKeys = [];
        foreach ($sortedTracks as $id => $track) {
            $trackKeys[] = $id;
            $nodeCount  += count($track->orderedEvents());
        }

        $edgeKeys = [];
        foreach ($sortedEdges->all() as $edge) {
            $edgeKeys[] = $edge->from->key() . '|' . $edge->to->key() . '|' . $edge->type->value;
        }

        $raw  = implode(',', [
            (string) $this->durationSec,
            implode(':', $trackKeys),
            implode(';', $edgeKeys),
        ]);
        $hash = sha1($raw);
        $us   = (float) sprintf('%.6f', microtime(true));
        $id   = substr($hash, 0, 8) . '@' . (int) ($us * 1_000_000);

        return new GraphSnapshot(
            id:           $id,
            snapshotHash: $hash,
            nodeCount:    $nodeCount,
            edgeCount:    $sortedEdges->count(),
            trackCount:   count($sortedTracks),
            frozenAtUs:   $us,
        );
    }

    private static function trackIdFor(TimelineTrack $track): string
    {
        $class = get_class($track);
        return defined("{$class}::ID") ? constant("{$class}::ID") : $class;
    }
}
