<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\StageRegistry;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use App\Services\AI\AFOS\Passes\Stages\CameraValidationStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier3Stage;
use PHPUnit\Framework\TestCase;

class StageRegistryTest extends TestCase
{
    // ── defaults() ────────────────────────────────────────────────────────────

    public function test_defaults_registers_all_six_stages(): void
    {
        $registry = StageRegistry::defaults();

        $this->assertTrue($registry->has(ShotValidationStage::class));
        $this->assertTrue($registry->has(Tier1Stage::class));
        $this->assertTrue($registry->has(Tier2Stage::class));
        $this->assertTrue($registry->has(CameraValidationStage::class));
        $this->assertTrue($registry->has(Tier3Stage::class));
        $this->assertTrue($registry->has(BackendStage::class));
    }

    public function test_defaults_registered_classes_count(): void
    {
        $classes = StageRegistry::defaults()->registeredClasses();
        $this->assertCount(6, $classes);
    }

    // ── resolve() ─────────────────────────────────────────────────────────────

    public function test_resolve_returns_correct_type(): void
    {
        $registry = StageRegistry::defaults();

        $this->assertInstanceOf(ShotValidationStage::class, $registry->resolve(ShotValidationStage::class));
        $this->assertInstanceOf(Tier1Stage::class,          $registry->resolve(Tier1Stage::class));
        $this->assertInstanceOf(Tier2Stage::class,          $registry->resolve(Tier2Stage::class));
        $this->assertInstanceOf(CameraValidationStage::class, $registry->resolve(CameraValidationStage::class));
        $this->assertInstanceOf(Tier3Stage::class,          $registry->resolve(Tier3Stage::class));
        $this->assertInstanceOf(BackendStage::class,        $registry->resolve(BackendStage::class));
    }

    public function test_resolve_returns_fresh_instance_each_time(): void
    {
        $registry = StageRegistry::defaults();

        $a = $registry->resolve(Tier1Stage::class);
        $b = $registry->resolve(Tier1Stage::class);

        $this->assertNotSame($a, $b, 'Registry must not cache instances — each resolve() call must return a new object');
    }

    public function test_resolve_unknown_class_throws(): void
    {
        $registry = StageRegistry::defaults();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/StageRegistry/');

        $registry->resolve('App\\NonExistentStage');
    }

    // ── register() immutability ────────────────────────────────────────────────

    public function test_register_returns_new_instance(): void
    {
        $original = StageRegistry::defaults();
        $extended = $original->register('SomeStage', fn() => $this->createMock(
            \App\Services\AI\AFOS\Passes\Pipeline\CompilerStage::class
        ));

        $this->assertNotSame($original, $extended);
    }

    public function test_register_does_not_mutate_original(): void
    {
        $original = StageRegistry::defaults();
        $original->register('SomeClass', fn() => null);

        $this->assertFalse($original->has('SomeClass'), 'Original registry must not be mutated by register()');
    }

    public function test_register_override_replaces_factory(): void
    {
        // Verify that registering under an existing key replaces the factory.
        // We create a mock CompilerStage whose name() returns 'Overridden'
        // and wire it under ShotValidationStage::class.
        $sentinel = $this->createMock(\App\Services\AI\AFOS\Passes\Pipeline\CompilerStage::class);
        $sentinel->method('name')->willReturn('Overridden');

        $registry = StageRegistry::defaults()
            ->register(ShotValidationStage::class, fn() => $sentinel);

        $resolved = $registry->resolve(ShotValidationStage::class);
        $this->assertSame('Overridden', $resolved->name());
    }

    // ── Plugin / custom stage registration ────────────────────────────────────

    public function test_custom_plugin_stage_resolved_correctly(): void
    {
        $customStage = $this->createMock(\App\Services\AI\AFOS\Passes\Pipeline\CompilerStage::class);
        $registry    = StageRegistry::defaults()
            ->register('Plugin\\MyCustomStage', fn() => $customStage);

        $this->assertTrue($registry->has('Plugin\\MyCustomStage'));
        $resolved = $registry->resolve('Plugin\\MyCustomStage');
        $this->assertSame($customStage, $resolved);
    }

    // ── fromRegistry() on PipelineDefinition ──────────────────────────────────

    public function test_from_registry_produces_six_stage_pipeline(): void
    {
        $def = PipelineDefinition::fromRegistry(StageRegistry::defaults());
        $this->assertCount(6, $def->stages());
    }

    public function test_from_registry_stage_order_matches_standard(): void
    {
        $fromReg      = PipelineDefinition::fromRegistry(StageRegistry::defaults());
        $standard     = PipelineDefinition::standard();

        $regNames     = array_map(fn($s) => $s->name(), $fromReg->stages());
        $standardNames = array_map(fn($s) => $s->name(), $standard->stages());

        $this->assertSame($standardNames, $regNames, 'fromRegistry() must produce stages in the same order as standard()');
    }

    public function test_from_registry_with_custom_class_list(): void
    {
        $def = PipelineDefinition::fromRegistry(StageRegistry::defaults(), [
            Tier1Stage::class,
            Tier3Stage::class,
            BackendStage::class,
        ]);

        $this->assertCount(3, $def->stages());
        $this->assertInstanceOf(Tier1Stage::class, $def->stages()[0]);
        $this->assertInstanceOf(Tier3Stage::class, $def->stages()[1]);
        $this->assertInstanceOf(BackendStage::class, $def->stages()[2]);
    }

    public function test_from_registry_pipeline_is_compilable(): void
    {
        $def     = PipelineDefinition::fromRegistry(StageRegistry::defaults());
        $manager = \App\Services\AI\AFOS\Passes\AfosPassManager::fromDefinition($def);

        $snap = $manager->compileWithSnapshot(
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'registry-test',
                'durationSec'        => 4.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'villa',
                'viewerShouldNotice' => ['villa'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'luxury',
                'energy'             => 0.4,
                'narrativeFunction'  => 'establish',
            ]),
            \App\Services\AI\AFOS\Creative\DirectorProfile::fromArray([
                'name'                => 'reg_dir',
                'observationWeight'   => 0.8,
                'motionWeight'        => 0.2,
                'revealWeight'        => 0.5,
                'negativeSpaceWeight' => 0.4,
                'symmetryWeight'      => 0.6,
                'cutFrequency'        => 'slow',
                'cameraPhilosophy'    => 'slow_observation',
                'colorPhilosophy'     => 'warm_golden',
            ]),
            \App\Services\AI\AFOS\Creative\CinematographyProfile::fromArray([
                'name'                 => 'reg_dp',
                'lensVocabularyMm'     => [35, 50],
                'lightingStyle'        => 'natural',
                'motionStyle'          => 'SLOW_PUSH',
                'depthLayersPreferred' => 2,
            ]),
            \App\Services\AI\AFOS\Creative\Intent::fromArray([
                'primaryEmotion'   => 'luxury',
                'secondaryEmotion' => null,
                'narrative'        => 'reveal_beauty',
                'tempo'            => 'meditative',
                'viewerExperience' => 'aspiration',
                'desiredTakeaway'  => 'Registry test output',
            ]),
        );

        $this->assertNotEmpty($snap->artifacts->compiledPrompt);
        $this->assertCount(6, $snap->profiles);
    }

    // ── Auto-resolve (zero-arg stage) ─────────────────────────────────────────

    public function test_auto_resolve_zero_arg_class(): void
    {
        // BackendStage has a zero-arg constructor; should auto-resolve even without explicit factory.
        // Build a registry with no factories via reflection (StageRegistry is final — no subclassing).
        $registry = (new \ReflectionClass(StageRegistry::class))->newInstanceWithoutConstructor();

        $prop = (new \ReflectionClass(StageRegistry::class))->getProperty('factories');
        $prop->setAccessible(true);
        $prop->setValue($registry, []);

        $stage = $registry->resolve(BackendStage::class);
        $this->assertInstanceOf(BackendStage::class, $stage);
    }
}
