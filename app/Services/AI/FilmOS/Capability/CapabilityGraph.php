<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Capability;

/**
 * Directed graph of capability dependencies.
 *
 * Models pipeline composition: IMAGE_TO_VIDEO requires TEXT_TO_IMAGE as precursor.
 * TEXT_TO_VIDEO has no precursor — it produces video directly from text.
 *
 * ProviderRouter consults this to determine what prerequisites a task needs
 * before it can be scheduled.
 *
 * Example:
 *   $graph = CapabilityGraph::defaults();
 *   $graph->dependenciesOf(CapabilityType::IMAGE_TO_VIDEO); // [TEXT_TO_IMAGE]
 *   $graph->dependenciesOf(CapabilityType::TEXT_TO_VIDEO);  // []
 */
final class CapabilityGraph
{
    /** @var array<string, CapabilityType[]>  capabilityValue → direct dependencies */
    private array $edges = [];

    public function requires(CapabilityType $capability, CapabilityType $dependency): void
    {
        $this->edges[$capability->value][] = $dependency;
    }

    /** Direct dependencies of a capability (not transitive). */
    public function dependenciesOf(CapabilityType $capability): array
    {
        return $this->edges[$capability->value] ?? [];
    }

    /**
     * Transitive closure: all capabilities that must complete before this one.
     * BFS — safe for the DAG structures used in FilmOS (no cycles expected).
     *
     * @return CapabilityType[]
     */
    public function transitiveDependencies(CapabilityType $capability): array
    {
        $visited = [];
        $queue   = [$capability];

        while ($queue) {
            $current = array_shift($queue);
            foreach ($this->dependenciesOf($current) as $dep) {
                if (!isset($visited[$dep->value])) {
                    $visited[$dep->value] = $dep;
                    $queue[]              = $dep;
                }
            }
        }

        return array_values($visited);
    }

    /**
     * Default pipeline graph encoding known capability relationships.
     *
     *   TEXT_TO_IMAGE  → (no deps)
     *   TEXT_TO_VIDEO  → (no deps)   Kling T2V generates directly
     *   IMAGE_TO_VIDEO → TEXT_TO_IMAGE
     *   UPSCALE        → TEXT_TO_IMAGE | TEXT_TO_VIDEO (either image or video frame)
     */
    public static function defaults(): self
    {
        $graph = new self();
        $graph->requires(CapabilityType::IMAGE_TO_VIDEO, CapabilityType::TEXT_TO_IMAGE);
        return $graph;
    }
}
