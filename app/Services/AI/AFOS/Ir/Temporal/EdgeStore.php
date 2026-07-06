<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * EdgeStore — immutable, indexed store for all EventEdge instances in a TemporalGraph.
 *
 * The EdgeStore is the Single Source of Truth for graph structure. It owns all
 * typed directed edges (EventEdge), while the tracks own the event nodes.
 *
 * Architecture:
 *   TemporalGraph
 *     ├── Tracks (nodes, by TrackId)
 *     └── EdgeStore (edges, indexed by from/to NodeRef)
 *
 * Immutability: every mutation returns a new EdgeStore. The original is unchanged.
 * Indexing: edges are indexed by from-key AND to-key for O(1) lookup in both directions.
 *
 * Phase 5 will add EventQuery / EdgeQuery fluent builders as entry points.
 * For now, edgesFrom() and edgesTo() are the primary access methods.
 *
 * Usage:
 *   $store = EdgeStore::empty()
 *       ->add(new EventEdge(NodeRef::motion('e101'), NodeRef::motion('e102'), RelationType::Follows))
 *       ->add(new EventEdge(NodeRef::motion('e101'), NodeRef::camera('cam_0'), RelationType::Supports));
 *
 *   $outgoing = $store->edgesFrom(NodeRef::motion('e101'));
 *   $hard     = $store->edgesFrom(NodeRef::motion('e101'), RelationType::Hard);
 */
final class EdgeStore
{
    /**
     * @param EventEdge[] $edges       All edges in insertion order.
     * @param array<string, EventEdge[]> $fromIndex  NodeRef::key() → EventEdge[]
     * @param array<string, EventEdge[]> $toIndex    NodeRef::key() → EventEdge[]
     */
    private function __construct(
        private readonly array $edges,
        private readonly array $fromIndex,
        private readonly array $toIndex,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    // ── Mutation (returns new instance) ───────────────────────────────────────

    public function add(EventEdge $edge): self
    {
        $fromKey = $edge->from->key();
        $toKey   = $edge->to->key();

        $fromIndex           = $this->fromIndex;
        $fromIndex[$fromKey][] = $edge;

        $toIndex           = $this->toIndex;
        $toIndex[$toKey][] = $edge;

        return new self([...$this->edges, $edge], $fromIndex, $toIndex);
    }

    /** Add multiple edges in one operation. */
    public function addAll(EventEdge ...$edges): self
    {
        $store = $this;
        foreach ($edges as $edge) {
            $store = $store->add($edge);
        }
        return $store;
    }

    /**
     * Remove an edge by identity (object equality).
     * O(n) — intended for optimizer rewrites, not hot paths.
     */
    public function remove(EventEdge $edge): self
    {
        $remaining = array_values(array_filter($this->edges, fn($e) => $e !== $edge));
        return self::fromEdges(...$remaining);
    }

    /**
     * Replace $old with $new edge atomically.
     * O(n) — rebuilds index. Used by SuggestionExecutor.
     */
    public function rewrite(EventEdge $old, EventEdge $new): self
    {
        $edges = array_map(fn($e) => $e === $old ? $new : $e, $this->edges);
        return self::fromEdges(...$edges);
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * All edges originating from $ref.
     * Pass RelationType args to filter: edgesFrom($ref, RelationType::Hard, RelationType::Follows)
     *
     * @return EventEdge[]
     */
    public function edgesFrom(NodeRef $ref, RelationType ...$types): array
    {
        $edges = $this->fromIndex[$ref->key()] ?? [];
        if (!empty($types)) {
            $edges = array_values(array_filter($edges, fn($e) => in_array($e->type, $types, true)));
        }
        return $edges;
    }

    /**
     * All edges pointing to $ref.
     *
     * @return EventEdge[]
     */
    public function edgesTo(NodeRef $ref, RelationType ...$types): array
    {
        $edges = $this->toIndex[$ref->key()] ?? [];
        if (!empty($types)) {
            $edges = array_values(array_filter($edges, fn($e) => in_array($e->type, $types, true)));
        }
        return $edges;
    }

    /**
     * All edges between two tracks.
     *
     * @return EventEdge[]
     */
    public function edgesBetweenTracks(string $trackIdA, string $trackIdB): array
    {
        return array_values(array_filter(
            $this->edges,
            fn($e) => ($e->from->trackId === $trackIdA && $e->to->trackId === $trackIdB)
                   || ($e->from->trackId === $trackIdB && $e->to->trackId === $trackIdA)
        ));
    }

    /**
     * All cross-track edges (from and to belong to different tracks).
     *
     * @return EventEdge[]
     */
    public function crossTrackEdges(): array
    {
        return array_values(array_filter($this->edges, fn($e) => $e->isCrossTrack()));
    }

    /** @return EventEdge[] All edges in insertion order. */
    public function all(): array
    {
        return $this->edges;
    }

    public function count(): int
    {
        return count($this->edges);
    }

    public function isEmpty(): bool
    {
        return $this->edges === [];
    }

    /**
     * Returns a new EdgeStore with all edges in canonical alphabetical order.
     * Used by TemporalGraph::freeze() to make the graph deterministic for hashing.
     *
     * Sort key: from.key() + '|' + to.key() + '|' + type.value
     */
    public function sorted(): self
    {
        $edges = $this->edges;
        usort($edges, fn(EventEdge $a, EventEdge $b) =>
            ($a->from->key() . '|' . $a->to->key() . '|' . $a->type->value)
            <=> ($b->from->key() . '|' . $b->to->key() . '|' . $b->type->value)
        );
        return self::fromEdges(...$edges);
    }

    // ── Factory helpers ───────────────────────────────────────────────────────

    /** Rebuild from a flat list of edges — used internally for remove/rewrite. */
    private static function fromEdges(EventEdge ...$edges): self
    {
        $store = self::empty();
        foreach ($edges as $edge) {
            $store = $store->add($edge);
        }
        return $store;
    }
}
