<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

use App\Services\AI\AFOS\Compiler\Validators\CameraIRValidator;
use App\Services\AI\AFOS\Compiler\Validators\ShotGoalIRValidator;
use App\Services\AI\AFOS\Passes\Camera\SimpleCameraPass;
use App\Services\AI\AFOS\Passes\Composition\SimpleCompositionPass;
use App\Services\AI\AFOS\Passes\Config\CameraPassConfig;
use App\Services\AI\AFOS\Passes\Config\CompositionPassConfig;
use App\Services\AI\AFOS\Passes\Prompt\PlannerResolver;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use App\Services\AI\AFOS\Passes\Stages\CameraArcStage;
use App\Services\AI\AFOS\Ir\Temporal\Motion\RuleBasedMotionPlanner;
use App\Services\AI\AFOS\Passes\Stages\CameraValidationStage;
use App\Services\AI\AFOS\Passes\Stages\MotionBeatStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\FreezeStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier3Stage;

/**
 * StageRegistry — factory registry for CompilerStage instances.
 *
 * Eliminates `new Tier1Stage(new SimpleCompositionPass(...))` from
 * PipelineDefinition::standard(), replacing manual wiring with lazy factory
 * callables. Plugins register new stages by calling register().
 *
 * Resolution priority:
 *   1. Explicit factory (registered via register())
 *   2. Zero-arg auto-instantiation (class_exists + new $class())
 *
 * Usage:
 *   // Standard pipeline via registry
 *   $def = PipelineDefinition::fromRegistry(StageRegistry::defaults());
 *
 *   // Custom/plugin stage
 *   $registry = StageRegistry::defaults()
 *       ->register(MyCustomStage::class, fn() => new MyCustomStage($config));
 *   $def = PipelineDefinition::fromRegistry($registry);
 *
 * Immutable: register() returns a new instance.
 */
final class StageRegistry
{
    /** @var array<string, callable(): CompilerStage> */
    private array $factories = [];

    private function __construct(array $factories = [])
    {
        $this->factories = $factories;
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /** Production-ready registry with all standard pipeline stages pre-wired. */
    public static function defaults(): self
    {
        return (new self())
            ->register(
                ShotValidationStage::class,
                fn() => new ShotValidationStage([new ShotGoalIRValidator()])
            )
            ->register(
                Tier1Stage::class,
                fn() => new Tier1Stage(new SimpleCompositionPass(CompositionPassConfig::defaults()))
            )
            ->register(
                MotionBeatStage::class,
                fn() => new MotionBeatStage(new RuleBasedMotionPlanner())
            )
            ->register(
                Tier2Stage::class,
                fn() => new Tier2Stage(new SimpleCameraPass(CameraPassConfig::defaults()))
            )
            ->register(
                CameraArcStage::class,
                fn() => new CameraArcStage()
            )
            ->register(
                CameraValidationStage::class,
                fn() => new CameraValidationStage([new CameraIRValidator()])
            )
            ->register(
                FreezeStage::class,
                fn() => new FreezeStage()
            )
            ->register(
                Tier3Stage::class,
                fn() => new Tier3Stage(PlannerResolver::withDefaults())
            )
            ->register(
                BackendStage::class,
                fn() => new BackendStage()
            );
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Register a factory for a stage class. Returns immutable clone.
     * Call this again to override an existing factory (useful for A/B testing).
     */
    public function register(string $stageClass, callable $factory): self
    {
        $clone                       = clone $this;
        $clone->factories[$stageClass] = $factory;
        return $clone;
    }

    /** True if this registry has an explicit factory for $stageClass. */
    public function has(string $stageClass): bool
    {
        return isset($this->factories[$stageClass]);
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    /**
     * Resolve a stage instance. Factories are called fresh each time —
     * the registry is stateless (no instance cache) to keep it immutable.
     *
     * @throws \RuntimeException If the stage is not registered and can't be auto-resolved.
     */
    public function resolve(string $stageClass): CompilerStage
    {
        if (isset($this->factories[$stageClass])) {
            return ($this->factories[$stageClass])();
        }

        // Auto-resolve: zero-arg instantiation for simple stages
        if (class_exists($stageClass)) {
            $reflection = new \ReflectionClass($stageClass);
            $ctor       = $reflection->getConstructor();

            if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                return new $stageClass();
            }
        }

        throw new \RuntimeException(
            "StageRegistry: no factory registered for '{$stageClass}' and it cannot be auto-resolved. " .
            "Register it with StageRegistry::register()."
        );
    }

    /** @return string[] All registered stage class names. */
    public function registeredClasses(): array
    {
        return array_keys($this->factories);
    }
}
