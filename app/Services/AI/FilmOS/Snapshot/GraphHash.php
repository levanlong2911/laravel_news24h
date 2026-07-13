<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Graph\Graph;

/**
 * Deterministic SHA-256 hash of a Graph's topology.
 *
 * Responsibilities (single):
 *   Produce a stable hash of graph structure (nodes + edges).
 *   Intent hashing  → PromptHashBuilder
 *   Task hashing    → SchedulerHashBuilder
 *   Policy hashing  → PolicyHashBuilder
 *
 * HashSerializer is injected so that Replay Servers in other runtimes
 * (Rust, Go, Node) can use the same format (MessagePack, CBOR, etc.)
 * and produce identical hashes without re-implementing json_encode semantics.
 *
 * Node contract  (HashableNode):  canonicalNode()  → CanonicalNode
 * Edge contract  (HashableEdge):  canonicalEdge()  → CanonicalEdge
 *   Edges without HashableEdge fall back to {from, to} only.
 *   Nodes without HashableNode throw LogicException — structural contract required.
 */
final class GraphHash
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * Canonical topology hash of a Graph.
     *
     * Nodes:  sorted by id (ksort) — canonical key order.
     * Edges:  each serialized independently, then sorted lexicographically.
     *
     * @throws \LogicException if any node does not implement HashableNode
     */
    public function of(Graph $graph): string
    {
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            if (!$node instanceof HashableNode) {
                throw new \LogicException(sprintf(
                    'Node %s (%s) must implement HashableNode to participate in a canonical hash.',
                    $node->id,
                    get_class($node),
                ));
            }
            $nodes[$node->id] = $node->canonicalNode()->toArray();
        }
        ksort($nodes);

        $edges = [];
        foreach ($graph->edges() as $edge) {
            $edges[] = $this->serializer->serialize(
                $edge instanceof HashableEdge
                    ? $edge->canonicalEdge()->toArray()
                    : ['from' => $edge->fromId, 'to' => $edge->toId],
            );
        }
        sort($edges);

        return $this->serializer->sha256(['nodes' => $nodes, 'edges' => $edges]);
    }
}
