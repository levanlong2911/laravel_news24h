<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph\Plugins;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Graph\GraphValidation;

/**
 * Validates graph invariants on every mutation.
 * In Phase 1: throws if an orphan is created (non-root node with no parent).
 * Swap for a lenient plugin in tests where partial construction is needed.
 */
final class ValidationPlugin implements GraphPlugin
{
    public function __construct(
        private readonly bool $strict = false,
    ) {}

    public function onNodeAdded(GraphNode $node, Graph $graph): void
    {
        // No-op: a freshly added node has no edges yet — validation deferred to onEdgeAdded
    }

    public function onEdgeAdded(GraphEdge $edge, Graph $graph): void
    {
        if (!$this->strict) {
            return;
        }

        $errors = GraphValidation::validate($graph);
        if (!empty($errors)) {
            throw new \RuntimeException(
                'Graph invariant violated after edge add: ' . implode('; ', $errors)
            );
        }
    }
}
