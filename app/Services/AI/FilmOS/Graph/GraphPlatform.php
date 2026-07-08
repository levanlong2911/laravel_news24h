<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Compatibility wrapper — delegates to GraphEngine.
 * Prefer GraphEngine::make() for new code.
 *
 * @deprecated Use GraphEngine::make() instead. Kept for existing callers.
 */
final class GraphPlatform
{
    private GraphEngine $engine;

    public function __construct()
    {
        $this->engine = GraphEngine::make();
    }

    public static function default(): self
    {
        return new self();
    }

    public function algorithms(): GraphAlgorithmsService
    {
        return $this->engine->algorithms();
    }

    public function query(): GraphQueryService
    {
        return $this->engine->query();
    }

    public function serializer(): GraphSerializerService
    {
        return $this->engine->serializer();
    }

    public function validator(): GraphValidationService
    {
        return $this->engine->validator();
    }

    public function traversal(): GraphTraversalService
    {
        return $this->engine->traversal();
    }
}
