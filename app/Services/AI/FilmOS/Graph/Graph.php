<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

use App\Services\AI\FilmOS\Graph\Plugins\GraphPlugin;

/**
 * Pure graph storage — nodes and edges only.
 * Does NOT implement any algorithms. Algorithms live in the Graph Platform:
 *   GraphAlgorithms  — topoSort, detectCycle, connectedComponents
 *   GraphTraversal   — bfs, dfs, traceBack
 *   GraphValidation  — hasOrphans, hasCycles, validate
 *   GraphQuery       — find, filter, ancestors, descendants
 *   GraphSerializer  — toArray, toJson
 *
 * Domain graphs extend this and add only domain-specific behavior.
 * Invariant 1 from ADR-016: all meaningful outputs are graphs.
 *
 * @template TNode of GraphNode
 * @template TEdge of GraphEdge
 */
abstract class Graph
{
    /** @var array<string, TNode> */
    private array $nodes = [];

    /** @var TEdge[] */
    private array $edges = [];

    /** @var GraphPlugin[] */
    private array $plugins = [];

    // ── Plugin registration ───────────────────────────────────────────────────

    public function use(GraphPlugin $plugin): static
    {
        $this->plugins[] = $plugin;
        return $this;
    }

    // ── Mutation ──────────────────────────────────────────────────────────────

    /** @param TNode $node */
    public function addNode(GraphNode $node): void
    {
        $this->nodes[$node->id] = $node;
        foreach ($this->plugins as $plugin) {
            $plugin->onNodeAdded($node, $this);
        }
    }

    /** @param TEdge $edge */
    public function addEdge(GraphEdge $edge): void
    {
        $this->edges[] = $edge;
        foreach ($this->plugins as $plugin) {
            $plugin->onEdgeAdded($edge, $this);
        }
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /** @return TNode[] */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return TEdge[] */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return TNode|null */
    public function node(string $id): ?GraphNode
    {
        return $this->nodes[$id] ?? null;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    public function edgeCount(): int
    {
        return count($this->edges);
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }
}
