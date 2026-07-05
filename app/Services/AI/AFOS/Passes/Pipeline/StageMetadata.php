<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * StageMetadata — self-description of a CompilerStage.
 *
 * All reads/writes should use ::class constants for refactor safety:
 *   reads: [ShotGoalIR::class, DirectorProfile::class]
 *
 * toArray() strips namespace so output stays readable.
 * PipelineDefinition::validate() uses the raw FQCNs for DAG checking.
 * DependencyGraph::build() uses reads/writes to construct the IR flow graph.
 *
 * Scheduler contract:
 *   $capabilities — fine-grained set used by scheduler / optimizer
 *   $cacheable    — high-level flag (derived: StageCapability::CACHEABLE present)
 *   $parallelizable — true when stage can safely run in parallel with siblings
 *   $cost          — structured estimate (ms / tokens / USD) for scheduler & benchmark
 */
final class StageMetadata
{
    public function __construct(
        public readonly string    $name,
        /** @var string[] FQCN or primitive key, e.g. ShotGoalIR::class or 'backendId' */
        public readonly array     $reads,
        /** @var string[] FQCN or primitive key this stage writes to PipelineState */
        public readonly array     $writes,
        /** Structured cost estimate: StageCost::cpu(ms) or StageCost::model(ms, tokens, usd). */
        public readonly StageCost $cost,
        public readonly string $description   = '',
        public readonly string $version       = '1.0',
        /** True when same input always produces identical output (enables caching). */
        public readonly bool   $deterministic  = true,
        /** Whether the stage output can be memoised given the same input hash. */
        public readonly bool   $cacheable      = false,
        /** True when the stage has no dependencies on previous stage side-effects. */
        public readonly bool   $parallelizable = false,
        /** Broad category: 'validation' | 'transform' | 'serialization' */
        public readonly string $category       = 'transform',
        /** @var StageCapability[] Fine-grained capability set for scheduler / optimizer. */
        public readonly array  $capabilities   = [],
    ) {}

    public function hasCapability(StageCapability $cap): bool
    {
        return in_array($cap, $this->capabilities, strict: true);
    }

    public function toArray(): array
    {
        return array_filter([
            'name'           => $this->name,
            'reads'          => array_map($this->shortName(...), $this->reads),
            'writes'         => array_map($this->shortName(...), $this->writes),
            'cost'           => $this->cost->toArray(),
            'category'       => $this->category,
            'version'        => $this->version,
            'deterministic'  => $this->deterministic,
            'cacheable'      => $this->cacheable,
            'parallelizable' => $this->parallelizable,
            'capabilities'   => array_map(fn(StageCapability $c) => $c->value, $this->capabilities) ?: null,
            'description'    => $this->description ?: null,
        ], fn($v) => $v !== null && $v !== [] && $v !== '');
    }

    private static function shortName(string $type): string
    {
        if (!str_contains($type, '\\')) {
            return $type;
        }
        $parts = explode('\\', $type);
        return end($parts);
    }
}
