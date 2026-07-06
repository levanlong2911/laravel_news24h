<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Validators\CameraIRValidator;
use App\Services\AI\AFOS\Compiler\Validators\ShotGoalIRValidator;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Camera\SimpleCameraPass;
use App\Services\AI\AFOS\Passes\Composition\SimpleCompositionPass;
use App\Services\AI\AFOS\Passes\Config\CameraPassConfig;
use App\Services\AI\AFOS\Passes\Config\CompositionPassConfig;
use App\Services\AI\AFOS\Backends\BackendEmitter;
use App\Services\AI\AFOS\Passes\Prompt\PlannerResolver;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use App\Services\AI\AFOS\Passes\Stages\CameraArcStage;
use App\Services\AI\AFOS\Passes\Stages\CameraValidationStage;
use App\Services\AI\AFOS\Ir\Temporal\Motion\RuleBasedMotionPlanner;
use App\Services\AI\AFOS\Passes\Stages\MotionBeatStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\FreezeStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier3Stage;

/**
 * PipelineDefinition — declarative description of a compiler pipeline.
 *
 * Separates pipeline STRUCTURE from pipeline EXECUTION.
 * AfosPassManager takes a PipelineDefinition and runs it — it never
 * knows which stages exist or in what order.
 *
 * Usage:
 *   $manager = AfosPassManager::fromDefinition(PipelineDefinition::standard());
 *
 * Custom pipeline (for A/B experiments or fast drafts):
 *   $def = PipelineDefinition::fromStages(
 *       new ShotValidationStage([new ShotGoalIRValidator()]),
 *       new Tier1Stage(new SimpleCompositionPass(...)),
 *       new Tier3Stage(new KlingPromptPlanningPass()),   // skip Tier2
 *       new BackendStage(),
 *   );
 *
 * Validate before running (DAG check):
 *   PipelineDefinition::standard()->validate()->assert();
 */
final class PipelineDefinition
{
    /** @param CompilerStage[] $stages */
    private function __construct(private readonly array $stages) {}

    public static function fromStages(CompilerStage ...$stages): self
    {
        return new self(array_values($stages));
    }

    /**
     * Build a pipeline by resolving stage classes through a StageRegistry.
     * Eliminates manual `new Tier1Stage(...)` wiring from call sites.
     *
     * Usage:
     *   PipelineDefinition::fromRegistry(StageRegistry::defaults());
     *   PipelineDefinition::fromRegistry($customRegistry, [Tier1Stage::class, BackendStage::class]);
     *
     * @param string[]|null $stageClasses Standard order used when omitted.
     */
    public static function fromRegistry(StageRegistry $registry, ?array $stageClasses = null): self
    {
        $stageClasses ??= [
            \App\Services\AI\AFOS\Passes\Stages\ShotValidationStage::class,
            \App\Services\AI\AFOS\Passes\Stages\Tier1Stage::class,
            \App\Services\AI\AFOS\Passes\Stages\MotionBeatStage::class,
            \App\Services\AI\AFOS\Passes\Stages\Tier2Stage::class,
            \App\Services\AI\AFOS\Passes\Stages\CameraArcStage::class,
            \App\Services\AI\AFOS\Passes\Stages\CameraValidationStage::class,
            \App\Services\AI\AFOS\Passes\Stages\FreezeStage::class,
            \App\Services\AI\AFOS\Passes\Stages\Tier3Stage::class,
            \App\Services\AI\AFOS\Passes\Stages\BackendStage::class,
        ];

        return new self(array_map([$registry, 'resolve'], $stageClasses));
    }

    /**
     * Standard Kling production pipeline — what defaults() uses.
     *
     * Level 0: [ShotValidation, Tier1]             — both read only inputs
     * Level 1: [MotionBeatStage, Tier2]             — parallel; both read CompositionIR
     * Level 2: [CameraArcStage, CameraValidation]   — parallel; both read CameraIR
     * Level 3: [FreezeStage]                        — seals TemporalGraph as immutable
     * Level 4: [Tier3]                              — reads frozen TemporalGraph → PromptIR
     * Level 5: [BackendStage]                       — PromptIR → compiled string
     */
    public static function standard(): self
    {
        return new self([
            new ShotValidationStage([new ShotGoalIRValidator()]),
            new Tier1Stage(new SimpleCompositionPass(CompositionPassConfig::defaults())),
            new MotionBeatStage(new RuleBasedMotionPlanner()),
            new Tier2Stage(new SimpleCameraPass(CameraPassConfig::defaults())),
            new CameraArcStage(),
            new CameraValidationStage([new CameraIRValidator()]),
            new FreezeStage(),
            new Tier3Stage(PlannerResolver::withDefaults()),
            new BackendStage(BackendEmitter::withDefaults()),
        ]);
    }

    /** @return CompilerStage[] */
    public function stages(): array
    {
        return $this->stages;
    }

    /** Machine-readable description of the pipeline for docs, CLI, and tooling. */
    public function describe(): array
    {
        return array_map(fn(CompilerStage $s) => $s->metadata()->toArray(), $this->stages);
    }

    /** Total number of stages. */
    public function count(): int
    {
        return count($this->stages);
    }

    /**
     * DAG validation: verify every stage's reads are produced before it runs.
     *
     * Uses the FQCN stored in StageMetadata::$reads/$writes (via ::class constants)
     * to check that no stage depends on IR that hasn't been produced yet.
     *
     * Initial available set = what PipelineState always has before stage 1.
     *
     * Usage:
     *   PipelineDefinition::standard()->validate()->assert();
     */
    public function validate(): PipelineValidationResult
    {
        $available = array_flip([
            ShotGoalIR::class,
            DirectorProfile::class,
            CinematographyProfile::class,
            Intent::class,
            DiagnosticBag::class,
            'backendId',
        ]);

        $errors = [];

        foreach ($this->stages as $stage) {
            $meta = $stage->metadata();

            foreach ($meta->reads as $read) {
                if (!isset($available[$read])) {
                    $short = $this->shortName($read);
                    $errors[] = "Stage '{$meta->name}' reads '{$short}' which has not been produced yet.";
                }
            }

            foreach ($meta->writes as $write) {
                $available[$write] = true;
            }
        }

        return new PipelineValidationResult($errors);
    }

    /**
     * Sum of all stage cost estimates for this pipeline.
     * Consumed by the scheduler (ETA), optimizer (which stages to skip),
     * and benchmark (estimate vs actual comparison).
     */
    public function estimatedCost(): StageCost
    {
        return array_reduce(
            $this->stages,
            fn(StageCost $carry, CompilerStage $stage) => $carry->add($stage->metadata()->cost),
            StageCost::free()
        );
    }

    /**
     * Build the IR dependency graph for this pipeline.
     * Useful for visualisation, parallel scheduling, and DAG optimisation.
     */
    public function buildGraph(): DependencyGraph
    {
        return DependencyGraph::build($this);
    }

    private function shortName(string $fqcn): string
    {
        if (!str_contains($fqcn, '\\')) {
            return $fqcn;
        }
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
