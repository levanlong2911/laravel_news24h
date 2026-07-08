<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateful wrapper around GraphValidation static utility.
 * Held by GraphEngine — allows test injection via GraphEngine::withValidator().
 */
final class GraphValidationService
{
    /** @return string[] */
    public function validate(Graph $graph): array
    {
        return GraphValidation::validate($graph);
    }

    public function hasOrphans(Graph $graph): bool
    {
        return GraphValidation::hasOrphans($graph);
    }

    public function hasCycles(Graph $graph): bool
    {
        return GraphValidation::hasCycles($graph);
    }

    /** @return string[] */
    public function findOrphanIds(Graph $graph): array
    {
        return GraphValidation::findOrphanIds($graph);
    }
}
