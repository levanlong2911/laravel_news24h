<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateful wrapper around GraphSerializer static utility.
 * Held by GraphEngine.
 */
final class GraphSerializerService
{
    public function toJson(Graph $graph): string
    {
        return GraphSerializer::toJson($graph);
    }

    /** @return array<string, mixed> */
    public function snapshot(Graph $graph): array
    {
        return GraphSerializer::snapshot($graph);
    }

    /** @return array<string, mixed> */
    public function toAdjacencyList(Graph $graph): array
    {
        return GraphSerializer::toAdjacencyList($graph);
    }

    /** @return array<array{string, string, string}> */
    public function toEdgeList(Graph $graph): array
    {
        return GraphSerializer::toEdgeList($graph);
    }
}
