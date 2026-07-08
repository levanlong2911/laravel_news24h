<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Serializes and describes a Graph as an array/JSON structure.
 * Used for checkpointing, debugging, and visualization.
 */
final class GraphSerializer
{
    /**
     * Snapshot: node count, edge count, node IDs — for checksums/checkpoints.
     * @return array<string, mixed>
     */
    public static function snapshot(Graph $graph): array
    {
        return [
            'nodeCount' => $graph->nodeCount(),
            'edgeCount' => $graph->edgeCount(),
            'nodeIds'   => array_map(fn(GraphNode $n) => $n->id, $graph->nodes()),
        ];
    }

    /**
     * Adjacency list representation — suitable for graph visualization.
     * @return array<string, mixed>
     */
    public static function toAdjacencyList(Graph $graph): array
    {
        $adj = [];
        foreach ($graph->nodes() as $node) {
            $adj[$node->id] = [
                'label'    => $node->label(),
                'isRoot'   => $node->isRoot(),
                'children' => [],
            ];
        }
        foreach ($graph->edges() as $edge) {
            $adj[$edge->fromId]['children'][] = [
                'to'    => $edge->toId,
                'label' => $edge->label(),
            ];
        }
        return $adj;
    }

    /**
     * Minimal edge list: [[fromId, toId, label], ...].
     * @return array<array{string, string, string}>
     */
    public static function toEdgeList(Graph $graph): array
    {
        return array_map(
            fn(GraphEdge $e) => [$e->fromId, $e->toId, $e->label()],
            $graph->edges(),
        );
    }

    public static function toJson(Graph $graph, int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode(self::toAdjacencyList($graph), $flags);
    }
}
