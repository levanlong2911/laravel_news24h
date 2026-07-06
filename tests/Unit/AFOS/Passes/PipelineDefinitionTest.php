<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use App\Services\AI\AFOS\Passes\Stages\CameraArcStage;
use App\Services\AI\AFOS\Passes\Stages\CameraValidationStage;
use App\Services\AI\AFOS\Passes\Stages\MotionBeatStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\FreezeStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier3Stage;
use PHPUnit\Framework\TestCase;

class PipelineDefinitionTest extends TestCase
{
    public function test_standard_has_nine_stages(): void
    {
        $def = PipelineDefinition::standard();
        $this->assertCount(9, $def->stages());
    }

    public function test_standard_stages_in_correct_order(): void
    {
        $stages = PipelineDefinition::standard()->stages();

        $this->assertInstanceOf(ShotValidationStage::class,  $stages[0]);
        $this->assertInstanceOf(Tier1Stage::class,            $stages[1]);
        $this->assertInstanceOf(MotionBeatStage::class,       $stages[2]);
        $this->assertInstanceOf(Tier2Stage::class,            $stages[3]);
        $this->assertInstanceOf(CameraArcStage::class,        $stages[4]);
        $this->assertInstanceOf(CameraValidationStage::class, $stages[5]);
        $this->assertInstanceOf(FreezeStage::class,            $stages[6]);
        $this->assertInstanceOf(Tier3Stage::class,            $stages[7]);
        $this->assertInstanceOf(BackendStage::class,          $stages[8]);
    }

    public function test_describe_returns_metadata_for_each_stage(): void
    {
        $description = PipelineDefinition::standard()->describe();

        $this->assertCount(9, $description);
        $this->assertSame('ShotValidationStage',   $description[0]['name']);
        $this->assertSame('Tier1Stage',             $description[1]['name']);
        $this->assertSame('MotionBeatStage',        $description[2]['name']);
        $this->assertSame('Tier2Stage',             $description[3]['name']);
        $this->assertSame('CameraArcStage',         $description[4]['name']);
        $this->assertSame('CameraValidationStage',  $description[5]['name']);
        $this->assertSame('FreezeStage',             $description[6]['name']);
        $this->assertSame('Tier3Stage',             $description[7]['name']);
        $this->assertSame('BackendStage',           $description[8]['name']);
    }

    public function test_describe_includes_reads_and_writes(): void
    {
        $description = PipelineDefinition::standard()->describe();

        $tier1 = $description[1];
        $this->assertContains('ShotGoalIR', $tier1['reads']);
        $this->assertContains('CompositionIR', $tier1['writes']);

        $tier2 = $description[3];
        $this->assertContains('CompositionIR', $tier2['reads']);
        $this->assertContains('CameraIR', $tier2['writes']);
    }

    public function test_from_definition_wires_into_pass_manager(): void
    {
        $manager = AfosPassManager::fromDefinition(PipelineDefinition::standard());
        $this->assertInstanceOf(AfosPassManager::class, $manager);
    }

    public function test_defaults_uses_pipeline_definition(): void
    {
        // defaults() and fromDefinition(standard()) should produce equivalent managers
        $default   = AfosPassManager::defaults();
        $fromDef   = AfosPassManager::fromDefinition(PipelineDefinition::standard());

        $this->assertInstanceOf(AfosPassManager::class, $default);
        $this->assertInstanceOf(AfosPassManager::class, $fromDef);
    }

    public function test_from_stages_builds_custom_pipeline(): void
    {
        $custom = PipelineDefinition::fromStages(
            new ShotValidationStage(),
            new BackendStage(),
        );

        $this->assertCount(2, $custom->stages());
        $this->assertInstanceOf(ShotValidationStage::class, $custom->stages()[0]);
        $this->assertInstanceOf(BackendStage::class,         $custom->stages()[1]);
    }

    public function test_all_stages_have_metadata(): void
    {
        foreach (PipelineDefinition::standard()->stages() as $stage) {
            $meta = $stage->metadata();
            $this->assertNotEmpty($meta->name);
            $this->assertIsArray($meta->reads);
            $this->assertIsArray($meta->writes);
            $this->assertInstanceOf(\App\Services\AI\AFOS\Passes\Pipeline\StageCost::class, $meta->cost);
            $this->assertGreaterThanOrEqual(0.0, $meta->cost->estimatedMs);
        }
    }

    public function test_stage_profiles_are_populated_after_compile(): void
    {
        // Full compilation — verify StageProfile array is populated
        $inputs  = $this->minimalInputs();
        $manager = AfosPassManager::defaults();
        $snapshot = $manager->compileWithSnapshot(...$inputs);

        $this->assertCount(9, $snapshot->profiles);

        $names = array_map(fn($p) => $p->stageName, $snapshot->profiles);
        $this->assertContains('ShotValidationStage',  $names);
        $this->assertContains('Tier1Stage',            $names);
        $this->assertContains('MotionBeatStage',       $names);
        $this->assertContains('Tier2Stage',            $names);
        $this->assertContains('CameraArcStage',        $names);
        $this->assertContains('CameraValidationStage', $names);
        $this->assertContains('FreezeStage',           $names);
        $this->assertContains('Tier3Stage',            $names);
        $this->assertContains('BackendStage',          $names);

        foreach ($snapshot->profiles as $profile) {
            $this->assertGreaterThanOrEqual(0.0, $profile->durationMs);
            $this->assertTrue($profile->succeeded);
        }
    }

    public function test_total_compile_ms_is_sum_of_stage_profiles(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->minimalInputs());

        $expected = array_sum(array_map(fn($p) => $p->durationMs, $snapshot->profiles));
        $this->assertEqualsWithDelta($expected, $snapshot->totalCompileMs(), 0.001);
    }

    public function test_profiles_not_included_in_to_array(): void
    {
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->minimalInputs());

        $arr = $snapshot->toArray();
        $this->assertArrayNotHasKey('profiles', $arr);
        $this->assertArrayNotHasKey('stage_profiles', $arr);
    }

    // ── Phase lifecycle ───────────────────────────────────────────────────────

    public function test_full_pipeline_state_is_in_lower_phase_after_compile(): void
    {
        // After a successful compile, the pipeline has progressed:
        // BUILD → FREEZE (FreezeStage) → LOWER (Tier3Stage)
        // We verify the final snapshot was produced by checking phase expectations
        // indirectly — no exception thrown means all phase assertions passed.
        $snapshot = AfosPassManager::defaults()->compileWithSnapshot(...$this->minimalInputs());
        $this->assertNotEmpty($snapshot->artifacts->compiledPrompt);
    }

    public function test_tier3_stage_throws_if_phase_is_not_freeze(): void
    {
        // If Tier3Stage runs before FreezeStage, pipeline is in BUILD phase → must throw.
        $inputs = $this->minimalInputs();
        $state  = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($inputs[0], $inputs[1], $inputs[2], $inputs[3]),
            new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag(),
        );
        // State is in BUILD phase by default. Tier3Stage expects FREEZE.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/expected freeze.*build/i');

        (new Tier3Stage())->run($state);
    }

    public function test_backend_stage_throws_if_phase_is_not_lower(): void
    {
        $inputs = $this->minimalInputs();
        $state  = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($inputs[0], $inputs[1], $inputs[2], $inputs[3]),
            new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag(),
        );
        // BUILD phase — BackendStage expects LOWER.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/expected lower.*build/i');

        (new BackendStage())->run($state);
    }

    public function test_sealed_nulls_out_mutable_graph_and_advances_phase(): void
    {
        $inputs = $this->minimalInputs();
        $initial = new \App\Services\AI\AFOS\Passes\Pipeline\PipelineState(
            new \App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs($inputs[0], $inputs[1], $inputs[2], $inputs[3]),
            new \App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag(),
        );

        // Put a mutable graph in state
        $mutableGraph = \App\Services\AI\AFOS\Ir\Temporal\TemporalGraph::empty(5.0);
        $withGraph    = $initial->withGraph($mutableGraph);
        $this->assertNotNull($withGraph->graph);
        $this->assertSame(CompilerPhase::BUILD, $withGraph->phase);

        // sealed() should null graph, set frozenGraph, advance phase
        $sealed = $withGraph->sealed($mutableGraph->freeze());
        $this->assertNull($sealed->graph,    'sealed() must release the mutable graph');
        $this->assertNotNull($sealed->frozenGraph);
        $this->assertSame(CompilerPhase::FREEZE, $sealed->phase);
    }

    private function minimalInputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'def-test',
                'durationSec'        => 5.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'hull',
                'viewerShouldNotice' => ['hull'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'serenity',
                'energy'             => 0.5,
                'narrativeFunction'  => 'establish',
            ]),
            \App\Services\AI\AFOS\Creative\DirectorProfile::fromArray([
                'name'                => 'test_director',
                'observationWeight'   => 0.7,
                'motionWeight'        => 0.3,
                'revealWeight'        => 0.4,
                'negativeSpaceWeight' => 0.5,
                'symmetryWeight'      => 0.3,
                'cutFrequency'        => 'slow',
                'cameraPhilosophy'    => 'slow_observation',
                'colorPhilosophy'     => 'warm_golden',
            ]),
            \App\Services\AI\AFOS\Creative\CinematographyProfile::fromArray([
                'name'                => 'test_dp',
                'lensVocabularyMm'    => [35, 85],
                'lightingStyle'       => 'natural',
                'motionStyle'         => 'SLOW_PUSH',
                'depthLayersPreferred' => 3,
            ]),
            \App\Services\AI\AFOS\Creative\Intent::fromArray([
                'primaryEmotion'   => 'serenity',
                'secondaryEmotion' => null,
                'narrative'        => 'reveal_beauty',
                'tempo'            => 'meditative',
                'viewerExperience' => 'aspiration',
                'desiredTakeaway'  => 'Test',
            ]),
        ];
    }
}
