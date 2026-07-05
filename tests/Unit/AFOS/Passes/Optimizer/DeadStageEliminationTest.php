<?php

namespace Tests\Unit\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\PassOptimizer;
use App\Services\AI\AFOS\Passes\Optimizer\Passes\DeadStageEliminationPass;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use PHPUnit\Framework\TestCase;

class DeadStageEliminationTest extends TestCase
{
    private DeadStageEliminationPass $pass;

    protected function setUp(): void
    {
        $this->pass = new DeadStageEliminationPass();
    }

    // ── Full mode ─────────────────────────────────────────────────────────────

    public function test_full_mode_keeps_all_six_stages(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $result = $this->pass->optimize($stages, OptimizationContext::full());
        $this->assertCount(6, $result);
    }

    public function test_full_mode_preserves_stage_order(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $result = $this->pass->optimize($stages, OptimizationContext::full());
        $names  = array_map(fn($s) => $s->name(), $result);

        $this->assertSame(
            ['ShotValidationStage', 'Tier1Stage', 'Tier2Stage', 'CameraValidationStage', 'Tier3Stage', 'BackendStage'],
            $names
        );
    }

    // ── Draft mode ────────────────────────────────────────────────────────────

    public function test_draft_mode_removes_shot_validation(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $result = $this->pass->optimize($stages, OptimizationContext::draft());
        $names  = array_map(fn($s) => $s->name(), $result);

        $this->assertNotContains('ShotValidationStage', $names);
    }

    public function test_draft_mode_removes_camera_validation(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $result = $this->pass->optimize($stages, OptimizationContext::draft());
        $names  = array_map(fn($s) => $s->name(), $result);

        $this->assertNotContains('CameraValidationStage', $names);
    }

    public function test_draft_mode_keeps_all_transform_stages(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $result = $this->pass->optimize($stages, OptimizationContext::draft());
        $names  = array_map(fn($s) => $s->name(), $result);

        $this->assertContains('Tier1Stage', $names);
        $this->assertContains('Tier2Stage', $names);
        $this->assertContains('Tier3Stage', $names);
        $this->assertContains('BackendStage', $names);
    }

    public function test_draft_mode_leaves_four_stages(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $result = $this->pass->optimize($stages, OptimizationContext::draft());
        $this->assertCount(4, $result);
    }

    // ── Custom required outputs ───────────────────────────────────────────────

    public function test_custom_required_outputs_eliminates_unreachable_stages(): void
    {
        // Draft mode + only need CompositionIR → only Tier1 survives.
        // In full mode, CameraValidationStage (writes=[]) is kept and adds CameraIR
        // to the needed set, which causes Tier2 to be kept transitively.
        // Draft mode skips validation stages, so Tier2 has nothing reading CameraIR.
        $stages = PipelineDefinition::standard()->stages();
        $ctx    = new OptimizationContext(
            requiredOutputs: [\App\Services\AI\AFOS\Ir\CompositionIR::class],
            mode: 'draft'
        );
        $result = $this->pass->optimize($stages, $ctx);
        $names  = array_map(fn($s) => $s->name(), $result);

        $this->assertContains('Tier1Stage', $names);
        $this->assertNotContains('Tier2Stage', $names);
        $this->assertNotContains('Tier3Stage', $names);
        $this->assertNotContains('BackendStage', $names);
    }

    public function test_full_mode_keeps_validation_transitive_deps(): void
    {
        // In full mode, CameraValidationStage reads CameraIR → Tier2 is NOT dead
        // even if compiledPrompt is not required.
        $stages = PipelineDefinition::standard()->stages();
        $ctx    = new OptimizationContext(
            requiredOutputs: [\App\Services\AI\AFOS\Ir\CompositionIR::class],
            mode: 'full'
        );
        $result = $this->pass->optimize($stages, $ctx);
        $names  = array_map(fn($s) => $s->name(), $result);

        // CameraValidation reads CameraIR → Tier2 is kept to supply it
        $this->assertContains('Tier2Stage', $names);
        // Backend and Tier3 are still dead (nothing reads compiledPrompt or PromptIR)
        $this->assertNotContains('BackendStage', $names);
        $this->assertNotContains('Tier3Stage', $names);
    }

    public function test_custom_required_outputs_keeps_transitive_deps(): void
    {
        // PromptIR requires Tier3, which requires Tier1 and Tier2
        $stages = PipelineDefinition::standard()->stages();
        $ctx    = new OptimizationContext(
            requiredOutputs: [\App\Services\AI\AFOS\Ir\PromptIR::class]
        );
        $result = $this->pass->optimize($stages, $ctx);
        $names  = array_map(fn($s) => $s->name(), $result);

        $this->assertContains('Tier1Stage', $names);
        $this->assertContains('Tier2Stage', $names);
        $this->assertContains('Tier3Stage', $names);
        $this->assertNotContains('BackendStage', $names); // compiledPrompt not required
    }

    // ── Pass metadata ─────────────────────────────────────────────────────────

    public function test_pass_name(): void
    {
        $this->assertSame('DeadStageElimination', $this->pass->name());
    }

    public function test_pass_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->pass->description());
    }

    // ── Integration: AfosPassManager with optimizer in draft mode ─────────────

    public function test_draft_mode_compile_produces_valid_output(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults(), OptimizationContext::draft());

        $snap = $manager->compileWithSnapshot(...$this->inputs());

        $this->assertNotEmpty($snap->artifacts->compiledPrompt);
    }

    public function test_draft_mode_compile_has_four_profiles(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults(), OptimizationContext::draft());

        $snap = $manager->compileWithSnapshot(...$this->inputs());

        $this->assertCount(4, $snap->profiles, 'Draft mode skips 2 validation stages');
    }

    public function test_draft_mode_metrics_shows_two_skipped_stages(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults(), OptimizationContext::draft());

        $snap    = $manager->compileWithSnapshot(...$this->inputs());
        $metrics = $snap->metrics();

        $this->assertSame(2, $metrics->skippedStages);
        $this->assertSame(4, $metrics->executedStages);
        $this->assertSame(6, $metrics->totalStages);
    }

    public function test_full_mode_metrics_shows_zero_skipped_stages(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults(), OptimizationContext::full());

        $snap    = $manager->compileWithSnapshot(...$this->inputs());
        $metrics = $snap->metrics();

        $this->assertSame(0, $metrics->skippedStages);
        $this->assertSame(6, $metrics->executedStages);
    }

    public function test_snapshot_has_execution_plan_when_optimizer_attached(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults());

        $snap = $manager->compileWithSnapshot(...$this->inputs());

        $this->assertNotNull($snap->executionPlan);
    }

    public function test_with_optimizer_is_immutable(): void
    {
        $original = AfosPassManager::defaults();
        $optimized = $original->withOptimizer(PassOptimizer::defaults());

        $this->assertNotSame($original, $optimized);

        // Original compiles without optimizer
        $snap = $original->compileWithSnapshot(...$this->inputs());
        $this->assertNull($snap->executionPlan);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function inputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'optimizer-test',
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
                'name'                => 'opt_dir',
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
                'name'                 => 'opt_dp',
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
                'desiredTakeaway'  => 'Optimizer test',
            ]),
        ];
    }
}
