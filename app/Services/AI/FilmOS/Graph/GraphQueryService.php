<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateful wrapper around GraphQuery static utility.
 * Held by GraphEngine — allows test injection via GraphEngine::withQuery().
 */
final class GraphQueryService
{
    /** @return GraphNode[] */
    public function find(Graph $graph, callable $predicate): array
    {
        return GraphQuery::find($graph, $predicate);
    }

    /** @return GraphEdge[] */
    public function filterEdges(Graph $graph, callable $predicate): array
    {
        return GraphQuery::filterEdges($graph, $predicate);
    }

    /** @return GraphNode[] */
    public function ancestors(Graph $graph, string $nodeId): array
    {
        return GraphQuery::ancestors($graph, $nodeId);
    }

    /** @return GraphNode[] */
    public function descendants(Graph $graph, string $nodeId): array
    {
        return GraphQuery::descendants($graph, $nodeId);
    }

    /** @return GraphNode[] */
    public function sources(Graph $graph): array
    {
        return GraphQuery::sources($graph);
    }

    /** @return GraphNode[] */
    public function sinks(Graph $graph): array
    {
        return GraphQuery::sinks($graph);
    }

    /** @return GraphNode[] */
    public function neighbors(Graph $graph, string $nodeId): array
    {
        return GraphQuery::neighbors($graph, $nodeId);
    }

    /** @return array<string, true> */
    public function reachableSet(Graph $graph, string $nodeId): array
    {
        return GraphQuery::reachableSet($graph, $nodeId);
    }

    public function commonAncestor(Graph $graph, string $aId, string $bId): ?GraphNode
    {
        return GraphQuery::commonAncestor($graph, $aId, $bId);
    }

    /**
     * @param  string[] $nodeIds
     * @return array{nodes: GraphNode[], edges: GraphEdge[]}
     */
    public function subgraph(Graph $graph, array $nodeIds): array
    {
        return GraphQuery::subgraph($graph, $nodeIds);
    }

    /** @return GraphNode[] */
    public function findByProperty(Graph $graph, string $property, mixed $value): array
    {
        return GraphQuery::findByProperty($graph, $property, $value);
    }
}
