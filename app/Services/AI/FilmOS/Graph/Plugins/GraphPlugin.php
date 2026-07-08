<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph\Plugins;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Graph\GraphNode;

/**
 * Base plugin interface for Graph Platform extensions.
 * Plugins are notified on every mutation and can add cross-cutting behavior:
 * observability, validation, versioning, diffing, etc.
 */
interface GraphPlugin
{
    public function onNodeAdded(GraphNode $node, Graph $graph): void;
    public function onEdgeAdded(GraphEdge $edge, Graph $graph): void;
}
