<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Value objects for graph mutation events.
 * Used by plugins (ObservabilityPlugin, ValidationPlugin, etc.) via the GraphPlugin interface.
 */
final class NodeAddedEvent
{
    public function __construct(
        public readonly GraphNode $node,
        public readonly float     $timestamp,
    ) {}
}

final class EdgeAddedEvent
{
    public function __construct(
        public readonly GraphEdge $edge,
        public readonly float     $timestamp,
    ) {}
}

final class GraphValidatedEvent
{
    public function __construct(
        /** @var string[] */
        public readonly array $errors,
        public readonly float $timestamp,
    ) {}

    public function isValid(): bool
    {
        return empty($this->errors);
    }
}
