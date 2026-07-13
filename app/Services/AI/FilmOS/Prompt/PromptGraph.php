<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

final class PromptGraph
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $shotId,
        /** @var array<string, PromptNode> keyed by "namespace.id" */
        private readonly array $nodes = [],
        /** @var PromptEdge[] */
        private readonly array $edges = [],
    ) {}

    public function withNode(PromptNode $node): self
    {
        return new self(
            $this->traceId,
            $this->shotId,
            array_merge($this->nodes, [$node->namespace.'.'.$node->id => $node]),
            $this->edges,
        );
    }

    public function withEdge(PromptEdge $edge): self
    {
        return new self($this->traceId, $this->shotId, $this->nodes, [...$this->edges, $edge]);
    }

    /** @return PromptNode[] */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return PromptEdge[] */
    public function edges(): array
    {
        return $this->edges;
    }

    public function node(string $id, string $namespace = 'render'): ?PromptNode
    {
        return $this->nodes[$namespace.'.'.$id] ?? null;
    }

    /** @return PromptNode[] across all namespaces */
    public function nodesByKey(string $id): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn (PromptNode $n) => $n->id === $id,
        ));
    }
}
