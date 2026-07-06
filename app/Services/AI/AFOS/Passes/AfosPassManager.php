<?php

namespace App\Services\AI\AFOS\Passes;

use App\Services\AI\AFOS\Compiler\CompilerContext;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Validators\CameraStageValidator;
use App\Services\AI\AFOS\Compiler\Validators\IRValidator;
use App\Services\AI\AFOS\Compiler\Validators\ShotGoalStageValidator;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\PromptIRSnapshot;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Observability\TraceCollector;
use App\Services\AI\AFOS\Passes\Camera\SimpleCameraPass;
use App\Services\AI\AFOS\Passes\Composition\SimpleCompositionPass;
use App\Services\AI\AFOS\Passes\Config\CameraPassConfig;
use App\Services\AI\AFOS\Passes\Config\CompositionPassConfig;
use App\Services\AI\AFOS\Passes\Cache\CompilerCache;
use App\Services\AI\AFOS\Passes\Events\PipelineEventBus;
use App\Services\AI\AFOS\Passes\Optimizer\ExecutionPlan;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\PassOptimizer;
use App\Services\AI\AFOS\Passes\Scheduler\Scheduler;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs;
use App\Services\AI\AFOS\Passes\Scheduler\SequentialScheduler;
use App\Services\AI\AFOS\Passes\Events\StageFailed;
use App\Services\AI\AFOS\Passes\Events\StageFinished;
use App\Services\AI\AFOS\Passes\Events\StageStarted;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageFingerprint;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageProfile;
use App\Services\AI\AFOS\Passes\Stages\CameraValidationStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;

/**
 * AfosPassManager — thin orchestrator for the AFOS compiler pipeline.
 *
 * Manages an ordered list of CompilerStage objects and runs them sequentially,
 * threading a PipelineState through each stage. All pipeline logic lives inside
 * the stages — PassManager has no knowledge of IR types or pass internals.
 *
 * Stage execution order (v1.0):
 *   ShotValidationStage   — validates ShotGoalIR; aborts on error
 *   Tier1Stage            — ShotGoalIR → CompositionIR
 *   Tier2Stage            — CompositionIR → CameraIR (+ capability clamping)
 *   CameraValidationStage — validates CameraIR; non-blocking
 *   Tier3Stage            — CameraIR + CompositionIR → PromptIR
 *   BackendStage          — PromptIR → compiled string
 *
 * Event Bus (optional):
 *   $manager = AfosPassManager::defaults()->withEventBus(new CallbackEventBus(...));
 *   // Emits: StageStarted → StageFinished | StageFailed per stage
 */
final class AfosPassManager
{
    private ?PipelineEventBus  $eventBus         = null;
    private ?CompilerCache     $cache            = null;
    private ?PassOptimizer     $optimizer        = null;
    private OptimizationContext $optimizerContext;
    private ?Scheduler         $scheduler        = null;

    /** @param CompilerStage[] $stages */
    public function __construct(private array $stages)
    {
        $this->optimizerContext = OptimizationContext::full();
    }

    public static function defaults(): self
    {
        return self::fromDefinition(PipelineDefinition::standard());
    }

    public static function fromDefinition(PipelineDefinition $definition): self
    {
        return new self($definition->stages());
    }

    // ── Event Bus ─────────────────────────────────────────────────────────────

    /** Attach an event bus; returns an immutable clone. */
    public function withEventBus(PipelineEventBus $bus): self
    {
        $clone           = clone $this;
        $clone->eventBus = $bus;
        return $clone;
    }

    /**
     * Attach a PassOptimizer; returns an immutable clone.
     * Before each compile, the optimizer transforms the stage list and computes
     * execution levels. Skipped stages are recorded in CompilerMetrics.
     */
    public function withOptimizer(PassOptimizer $optimizer, ?OptimizationContext $context = null): self
    {
        $clone                  = clone $this;
        $clone->optimizer       = $optimizer;
        $clone->optimizerContext = $context ?? OptimizationContext::full();
        return $clone;
    }

    /**
     * Attach a Scheduler; returns an immutable clone.
     *
     * When a scheduler is set AND an optimizer is attached, compileWithSnapshot()
     * delegates stage execution to the scheduler instead of the manual profiling loop.
     * Use SequentialScheduler for debugging, ParallelScheduler for production.
     */
    public function withScheduler(Scheduler $scheduler): self
    {
        $clone            = clone $this;
        $clone->scheduler = $scheduler;
        return $clone;
    }

    /**
     * Attach a CompilerCache; returns an immutable clone.
     * Stages with StageCapability::CACHEABLE will be skipped on fingerprint hit.
     */
    public function withCache(CompilerCache $cache): self
    {
        $clone        = clone $this;
        $clone->cache = $cache;
        return $clone;
    }

    // ── Stage replacement helpers ──────────────────────────────────────────────

    /**
     * Replace every stage that is an instanceof $stageClass using $transform.
     * Returns a new manager — immutable.
     */
    public function withStage(string $stageClass, callable $transform): self
    {
        $stages = array_map(
            fn(CompilerStage $s) => $s instanceof $stageClass ? $transform($s) : $s,
            $this->stages
        );
        $clone         = clone $this;
        $clone->stages = $stages;
        return $clone;
    }

    public function withCompositionConfig(CompositionPassConfig $config): self
    {
        return $this->withStage(Tier1Stage::class, fn(Tier1Stage $s) => $s->withPass(new SimpleCompositionPass($config)));
    }

    public function withCameraConfig(CameraPassConfig $config): self
    {
        return $this->withStage(Tier2Stage::class, fn(Tier2Stage $s) => $s->withPass(new SimpleCameraPass($config)));
    }

    /**
     * Add a validator, routed to the correct stage by instanceof.
     * ShotGoalStageValidator → ShotValidationStage (blocking before Tier 1).
     * CameraStageValidator   → CameraValidationStage (non-blocking after Tier 2).
     */
    public function withValidator(IRValidator $validator): self
    {
        if ($validator instanceof ShotGoalStageValidator) {
            return $this->withStage(ShotValidationStage::class, fn(ShotValidationStage $s) => $s->withValidator($validator));
        }
        if ($validator instanceof CameraStageValidator) {
            return $this->withStage(CameraValidationStage::class, fn(CameraValidationStage $s) => $s->withValidator($validator));
        }
        return $this;
    }

    /** Add a CompositionIR refinement pass: fn(CompositionIR, DirectorProfile, CinematographyProfile): CompositionIR */
    public function addCompositionRefinement(callable $pass): self
    {
        return $this->withStage(Tier1Stage::class, fn(Tier1Stage $s) => $s->addRefinement($pass));
    }

    /** Add a CameraIR refinement pass: fn(CameraIR, DirectorProfile, CinematographyProfile): CameraIR */
    public function addCameraRefinement(callable $pass): self
    {
        return $this->withStage(Tier2Stage::class, fn(Tier2Stage $s) => $s->addRefinement($pass));
    }

    // ── Compilation API ───────────────────────────────────────────────────────

    public function compileFromContext(CompilerContext $ctx): PromptIRSnapshot
    {
        // CompilerContext is the public API entry point (caller-facing, owns diagnostics pre-init).
        // PipelineInputs is the compiler-internal form (carries backendId, no diagnostics).
        // Boundary: CompilerContext → PipelineInputs → PipelineState.
        return $this->runPipeline(new PipelineState(
            inputs: new PipelineInputs($ctx->shot, $ctx->director, $ctx->dp, $ctx->intent, 'kling', $ctx->trace),
            bag:    $ctx->diagnostics,
        ));
    }

    /**
     * Run the full pipeline and return a PromptIRSnapshot with all IR artifacts,
     * accumulated diagnostics, and per-stage telemetry profiles.
     *
     * Each stage emits StageStarted → StageFinished|StageFailed on the event bus.
     * Per-stage memory delta and diagnostic counts are recorded in StageProfile.
     */
    public function compileWithSnapshot(
        ShotGoalIR            $shot,
        DirectorProfile       $director,
        CinematographyProfile $dp,
        Intent                $intent,
        ?TraceCollector       $trace       = null,
        ?DiagnosticBag        $diagnostics = null,
    ): PromptIRSnapshot {
        return $this->runPipeline(new PipelineState(
            inputs: new PipelineInputs($shot, $director, $dp, $intent, 'kling', $trace),
            bag:    $diagnostics ?? new DiagnosticBag(),
        ));
    }

    /**
     * Core execution engine — runs the optimizer/scheduler loop and returns a snapshot.
     *
     * Both compileWithSnapshot() and compileFromContext() delegate here after constructing
     * their respective PipelineState. This is the single point of pipeline execution.
     */
    private function runPipeline(PipelineState $state): PromptIRSnapshot
    {

        // Run optimizer to get the execution plan (may remove or reorder stages)
        $plan        = null;
        $stagesToRun = $this->stages;
        if ($this->optimizer !== null) {
            $def         = PipelineDefinition::fromStages(...$this->stages);
            $plan        = $this->optimizer->optimize($def, $this->optimizerContext);
            $stagesToRun = $plan->flatStages();
        }

        $profiles = [];

        // Scheduler path: delegate level-by-level execution when both optimizer and
        // scheduler are present. Skips per-stage profiling (metrics come from plan).
        if ($plan !== null && $this->scheduler !== null) {
            $state = $this->scheduler->execute($plan, $state);
        } else {
        foreach ($stagesToRun as $stage) {
            $errBefore  = count($state->bag->errors());
            $warnBefore = count($state->bag->warnings());
            $hintBefore = count($state->bag->hints());
            $memBefore  = memory_get_usage();
            $t0         = hrtime(true);

            $this->eventBus?->dispatch(new StageStarted($stage->name(), $stage->metadata()));

            // Cache check: only for CACHEABLE stages when a cache is attached
            $cacheHit = false;
            if ($this->cache !== null && $stage->metadata()->hasCapability(StageCapability::CACHEABLE)) {
                $fp       = StageFingerprint::of($stage, $state);
                $restored = $this->cache->get($fp, $state);

                if ($restored !== null) {
                    $state      = $restored;
                    $durationMs = round((hrtime(true) - $t0) / 1_000_000, 3);
                    $memAfter   = memory_get_usage();
                    $cacheHit   = true;

                    $profile = new StageProfile(
                        stageName:    $stage->name(),
                        durationMs:   $durationMs,
                        succeeded:    true,
                        memoryBefore: $memBefore,
                        memoryAfter:  $memAfter,
                    );

                    $this->eventBus?->dispatch(new StageFinished($stage->name(), $profile));
                    $profiles[] = $profile;
                    continue;
                }
            }

            try {
                $state      = $stage->run($state);
                $durationMs = round((hrtime(true) - $t0) / 1_000_000, 3);
                $memAfter   = memory_get_usage();

                // Populate cache on miss
                if ($this->cache !== null && !$cacheHit && $stage->metadata()->hasCapability(StageCapability::CACHEABLE)) {
                    $this->cache->put($fp, $state);
                }

                $profile = new StageProfile(
                    stageName:    $stage->name(),
                    durationMs:   $durationMs,
                    succeeded:    true,
                    memoryBefore: $memBefore,
                    memoryAfter:  $memAfter,
                    errorCount:   count($state->bag->errors()) - $errBefore,
                    warningCount: count($state->bag->warnings()) - $warnBefore,
                    hintCount:    count($state->bag->hints()) - $hintBefore,
                );

                $this->eventBus?->dispatch(new StageFinished($stage->name(), $profile));
            } catch (\Throwable $e) {
                $durationMs = round((hrtime(true) - $t0) / 1_000_000, 3);
                $memAfter   = memory_get_usage();

                $profile = new StageProfile(
                    stageName:    $stage->name(),
                    durationMs:   $durationMs,
                    succeeded:    false,
                    memoryBefore: $memBefore,
                    memoryAfter:  $memAfter,
                    errorCount:   count($state->bag->errors()) - $errBefore,
                    warningCount: count($state->bag->warnings()) - $warnBefore,
                    hintCount:    count($state->bag->hints()) - $hintBefore,
                );

                $this->eventBus?->dispatch(new StageFailed($stage->name(), $profile, $e));
                throw $e;
            }

            $profiles[] = $profile;
        }
        } // end else (profiling loop)

        $estimatedCost = $plan?->estimatedCost ?? $this->pipelineEstimatedCost();

        return PromptIRSnapshot::build(
            $state->shot, $state->camera, $state->composition,
            $state->intent, $state->compiledPrompt, $state->backendId,
            $state->bag, $profiles, $estimatedCost, $plan
        );
    }

    /** Sum of all stage cost estimates — used to populate PromptIRSnapshot::estimatedCost. */
    private function pipelineEstimatedCost(): StageCost
    {
        return array_reduce(
            $this->stages,
            fn(StageCost $carry, CompilerStage $stage) => $carry->add($stage->metadata()->cost),
            StageCost::free()
        );
    }

    /** Compile to prompt string. GraphAssembler calls this — signature unchanged. */
    public function compile(
        ShotGoalIR            $shot,
        DirectorProfile       $director,
        CinematographyProfile $dp,
        Intent                $intent,
        ?TraceCollector       $trace = null,
    ): string {
        return $this->compileWithSnapshot($shot, $director, $dp, $intent, $trace)->artifacts->compiledPrompt;
    }
}
