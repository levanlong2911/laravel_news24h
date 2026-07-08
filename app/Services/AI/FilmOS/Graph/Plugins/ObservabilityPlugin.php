<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph\Plugins;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Observability\FilmOSProfiler;

/**
 * Tracks node/edge additions for observability — feeds into FilmOSProfiler.
 */
final class ObservabilityPlugin implements GraphPlugin
{
    public function __construct(
        private readonly FilmOSProfiler $profiler,
        private readonly string         $graphName,
    ) {}

    public function onNodeAdded(GraphNode $node, Graph $graph): void
    {
        $this->profiler->incrementCounter("{$this->graphName}.node_count");
    }

    public function onEdgeAdded(GraphEdge $edge, Graph $graph): void
    {
        $this->profiler->incrementCounter("{$this->graphName}.edge_count");
    }
}
