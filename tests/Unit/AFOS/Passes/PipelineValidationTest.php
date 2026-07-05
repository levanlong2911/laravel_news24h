<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineValidationResult;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier3Stage;
use PHPUnit\Framework\TestCase;

class PipelineValidationTest extends TestCase
{
    public function test_standard_pipeline_is_valid(): void
    {
        $result = PipelineDefinition::standard()->validate();

        $this->assertTrue($result->valid);
        $this->assertEmpty($result->errors);
    }

    public function test_assert_does_not_throw_on_valid_pipeline(): void
    {
        $this->expectNotToPerformAssertions();
        PipelineDefinition::standard()->validate()->assert();
    }

    public function test_broken_pipeline_missing_composition_ir(): void
    {
        // Tier2 reads CompositionIR but Tier1 (which produces it) is skipped
        $broken = PipelineDefinition::fromStages(
            new ShotValidationStage(),
            new Tier2Stage(new \App\Services\AI\AFOS\Passes\Camera\SimpleCameraPass(
                \App\Services\AI\AFOS\Passes\Config\CameraPassConfig::defaults()
            )),
            new BackendStage(),
        );

        $result = $broken->validate();

        $this->assertFalse($result->valid);
        $this->assertNotEmpty($result->errors);

        // Error should mention the missing IR type
        $errorText = implode(' ', $result->errors);
        $this->assertStringContainsString('CompositionIR', $errorText);
    }

    public function test_broken_pipeline_missing_camera_ir(): void
    {
        $broken = PipelineDefinition::fromStages(
            new ShotValidationStage(),
            new Tier1Stage(new \App\Services\AI\AFOS\Passes\Composition\SimpleCompositionPass(
                \App\Services\AI\AFOS\Passes\Config\CompositionPassConfig::defaults()
            )),
            // Skip Tier2 — Tier3 reads CameraIR which was never produced
            new Tier3Stage(new \App\Services\AI\AFOS\Passes\Prompt\KlingPromptPlanningPass()),
            new BackendStage(),
        );

        $result = $broken->validate();

        $this->assertFalse($result->valid);
        $errorText = implode(' ', $result->errors);
        $this->assertStringContainsString('CameraIR', $errorText);
    }

    public function test_multiple_missing_irs_all_reported(): void
    {
        // Jump straight to backend — skips Tier1, Tier2, Tier3
        $broken = PipelineDefinition::fromStages(
            new ShotValidationStage(),
            new BackendStage(),
        );

        $result = $broken->validate();

        $this->assertFalse($result->valid);
        // BackendStage reads PromptIR — that's missing
        $errorText = implode(' ', $result->errors);
        $this->assertStringContainsString('PromptIR', $errorText);
    }

    public function test_assert_throws_on_invalid_pipeline(): void
    {
        $broken = PipelineDefinition::fromStages(
            new BackendStage(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pipeline DAG validation failed/');
        $broken->validate()->assert();
    }

    public function test_validation_result_is_value_object(): void
    {
        $valid   = new PipelineValidationResult([]);
        $invalid = new PipelineValidationResult(['Stage X reads Y which has not been produced yet.']);

        $this->assertTrue($valid->valid);
        $this->assertFalse($invalid->valid);
        $this->assertCount(1, $invalid->errors);
    }

    public function test_stage_metadata_reads_use_class_constants(): void
    {
        // Verify stages declare reads using FQCN (class constants), not short strings
        $stages = PipelineDefinition::standard()->stages();

        foreach ($stages as $stage) {
            foreach ($stage->metadata()->reads as $read) {
                if (str_contains($read, '\\')) {
                    $this->assertTrue(class_exists($read), "Read FQCN '{$read}' must be a valid class");
                }
            }
            foreach ($stage->metadata()->writes as $write) {
                if (str_contains($write, '\\')) {
                    $this->assertTrue(class_exists($write), "Write FQCN '{$write}' must be a valid class");
                }
            }
        }
    }

    public function test_stage_metadata_has_required_behavioral_flags(): void
    {
        foreach (PipelineDefinition::standard()->stages() as $stage) {
            $meta = $stage->metadata();
            $this->assertIsBool($meta->deterministic,  "{$meta->name}: deterministic must be bool");
            $this->assertIsBool($meta->cacheable,       "{$meta->name}: cacheable must be bool");
            $this->assertIsBool($meta->parallelizable,  "{$meta->name}: parallelizable must be bool");
            $this->assertContains($meta->category, ['validation', 'transform', 'serialization'],
                "{$meta->name}: category must be one of the defined values");
            $this->assertNotEmpty($meta->version, "{$meta->name}: version must not be empty");
        }
    }

    public function test_describe_shows_short_class_names(): void
    {
        $description = PipelineDefinition::standard()->describe();

        // reads/writes in toArray() show short names, not FQCNs
        $tier1 = $description[1];
        foreach ($tier1['reads'] as $read) {
            $this->assertStringNotContainsString('\\', $read, "toArray() reads should be short names, not FQCNs");
        }
        $this->assertContains('ShotGoalIR', $tier1['reads']);
        $this->assertContains('CompositionIR', $tier1['writes']);
    }

    public function test_describe_includes_behavioral_flags(): void
    {
        $description = PipelineDefinition::standard()->describe();

        foreach ($description as $stageMeta) {
            $this->assertArrayHasKey('deterministic',  $stageMeta);
            $this->assertArrayHasKey('cacheable',       $stageMeta);
            $this->assertArrayHasKey('parallelizable',  $stageMeta);
            $this->assertArrayHasKey('category',        $stageMeta);
        }
    }

    public function test_stage_profile_tracks_memory_delta(): void
    {
        $snapshot = \App\Services\AI\AFOS\Passes\AfosPassManager::defaults()
            ->compileWithSnapshot(...$this->minimalInputs());

        foreach ($snapshot->profiles as $profile) {
            $this->assertIsInt($profile->memoryDelta);
            $this->assertSame($profile->memoryAfter - $profile->memoryBefore, $profile->memoryDelta);
        }
    }

    public function test_stage_profile_tracks_diagnostic_counts(): void
    {
        $snapshot = \App\Services\AI\AFOS\Passes\AfosPassManager::defaults()
            ->compileWithSnapshot(...$this->minimalInputs());

        foreach ($snapshot->profiles as $profile) {
            $this->assertIsInt($profile->errorCount);
            $this->assertIsInt($profile->warningCount);
            $this->assertIsInt($profile->hintCount);
            $this->assertGreaterThanOrEqual(0, $profile->errorCount);
            $this->assertGreaterThanOrEqual(0, $profile->warningCount);
            $this->assertGreaterThanOrEqual(0, $profile->hintCount);
        }
    }

    private function minimalInputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'val-test',
                'durationSec'        => 5.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'pool',
                'viewerShouldNotice' => ['pool'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'serenity',
                'energy'             => 0.5,
                'narrativeFunction'  => 'establish',
            ]),
            \App\Services\AI\AFOS\Creative\DirectorProfile::fromArray([
                'name'                => 'val_dir',
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
                'name'                 => 'val_dp',
                'lensVocabularyMm'     => [35, 85],
                'lightingStyle'        => 'natural',
                'motionStyle'          => 'SLOW_PUSH',
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
