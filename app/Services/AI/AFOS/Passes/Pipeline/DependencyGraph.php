<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * DependencyGraph — IR flow graph built from StageMetadata reads/writes.
 *
 * Constructed from a PipelineDefinition. Each node is a stage; each directed
 * edge A → B means "A produces IR that B reads". A future parallel scheduler
 * can run stages whose all upstream edges are resolved.
 *
 * Usage:
 *   $graph = DependencyGraph::build(PipelineDefinition::standard());
 *   $graph->hasCycle();          // false for a valid pipeline
 *   $graph->topologicalOrder();  // execution order for a parallel scheduler
 *   $graph->describe();          // machine-readable for docs / visualiser
 */
final class DependencyGraph
{
    /**
     * @param array<string, StageMetadata> $nodes     stageName → StageMetadata
     * @param array<string, string[]>       $upstream  stageName → [upstream stageNames]
     * @param array<string, string[]>       $downstream stageName → [downstream stageNames]
     */
    private function __construct(
        private readonly array $nodes,
        private readonly array $upstream,
        private readonly array $downstream,
    ) {}

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function build(PipelineDefinition $definition): self
    {
        $stages = $definition->stages();

        // Pass 1: record which stage(s) produce each IR FQCN
        // array<string, string[]> supports multiple producers of the same IR type
        $producedBy = [];    // fqcn → stageName[]
        $nodes      = [];    // stageName → StageMetadata

        foreach ($stages as $stage) {
            $meta               = $stage->metadata();
            $nodes[$meta->name] = $meta;

            foreach ($meta->writes as $fqcn) {
                $producedBy[$fqcn][] = $meta->name;
            }
        }

        // Pass 2: build upstream / downstream edges
        $upstream   = array_fill_keys(array_keys($nodes), []);
        $downstream = array_fill_keys(array_keys($nodes), []);

        foreach ($stages as $stage) {
            $meta = $stage->metadata();

            foreach ($meta->reads as $fqcn) {
                if (!isset($producedBy[$fqcn])) {
                    continue; // produced by initial PipelineState (not a stage edge)
                }

                foreach ($producedBy[$fqcn] as $producer) {
                    if (!in_array($producer, $upstream[$meta->name], strict: true)) {
                        $upstream[$meta->name][]  = $producer;
                        $downstream[$producer][]  = $meta->name;
                    }
                }
            }
        }

        return new self($nodes, $upstream, $downstream);
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /** @return string[] All stage names in definition order. */
    public function stageNames(): array
    {
        return array_keys($this->nodes);
    }

    public function metadataOf(string $stageName): StageMetadata
    {
        return $this->nodes[$stageName] ?? throw new \RuntimeException("Unknown stage: {$stageName}");
    }

    /** @return string[] Names of stages that must finish before $stageName. */
    public function upstreamsOf(string $stageName): array
    {
        return $this->upstream[$stageName] ?? [];
    }

    /** @return string[] Names of stages that depend on $stageName's output. */
    public function downstreamsOf(string $stageName): array
    {
        return $this->downstream[$stageName] ?? [];
    }

    /** @return string[] Names of stages with no upstream dependencies (entry points). */
    public function entryPoints(): array
    {
        return array_keys(array_filter($this->upstream, fn($ups) => empty($ups)));
    }

    // ── Cycle detection (DFS coloring) ────────────────────────────────────────

    public function hasCycle(): bool
    {
        $color  = array_fill_keys(array_keys($this->nodes), 'white');
        $hasCycle = false;

        $visit = function (string $node) use (&$color, &$hasCycle, &$visit): void {
            if ($color[$node] === 'gray') { $hasCycle = true; return; }
            if ($color[$node] === 'black') { return; }

            $color[$node] = 'gray';
            foreach ($this->downstream[$node] ?? [] as $child) {
                $visit($child);
            }
            $color[$node] = 'black';
        };

        foreach (array_keys($this->nodes) as $node) {
            if ($color[$node] === 'white') {
                $visit($node);
            }
        }

        return $hasCycle;
    }

    // ── Topological sort (Kahn's algorithm) ───────────────────────────────────

    /**
     * Returns stages in an order where every stage's upstreams appear before it.
     * Throws if the graph has a cycle.
     *
     * @return string[]
     */
    public function topologicalOrder(): array
    {
        $inDegree = [];
        foreach (array_keys($this->nodes) as $name) {
            $inDegree[$name] = count($this->upstream[$name]);
        }

        $queue  = array_keys(array_filter($inDegree, fn($d) => $d === 0));
        $result = [];

        while (!empty($queue)) {
            $node = array_shift($queue);
            $result[] = $node;

            foreach ($this->downstream[$node] ?? [] as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        if (count($result) !== count($this->nodes)) {
            throw new \RuntimeException('DependencyGraph::topologicalOrder() failed: cycle detected.');
        }

        return $result;
    }

    // ── Serialisation ─────────────────────────────────────────────────────────

    /**
     * Machine-readable graph description: nodes + edges.
     * Suitable for API responses, CLI output, or a visual pipeline renderer.
     */
    public function describe(): array
    {
        $edges = [];
        foreach ($this->downstream as $from => $tos) {
            foreach ($tos as $to) {
                $edges[] = ['from' => $from, 'to' => $to];
            }
        }

        return [
            'nodes' => array_map(fn(StageMetadata $m) => [
                'name'         => $m->name,
                'category'     => $m->category,
                'cost'         => $m->cost,
                'cacheable'    => $m->cacheable,
                'capabilities' => array_map(fn(StageCapability $c) => $c->value, $m->capabilities),
                'upstream'     => $this->upstream[$m->name],
                'downstream'   => $this->downstream[$m->name],
            ], $this->nodes),
            'edges'       => $edges,
            'entry_points' => $this->entryPoints(),
            'has_cycle'   => $this->hasCycle(),
        ];
    }
}
