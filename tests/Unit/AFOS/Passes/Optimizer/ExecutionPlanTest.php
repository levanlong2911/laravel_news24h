<?php

namespace Tests\Unit\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Optimizer\ExecutionPlan;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\PassOptimizer;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use PHPUnit\Framework\TestCase;

class ExecutionPlanTest extends TestCase
{
    private PassOptimizer $optimizer;
    private PipelineDefinition $def;

    protected function setUp(): void
    {
        $this->optimizer = PassOptimizer::defaults();
        $this->def       = PipelineDefinition::standard();
    }

    // ── Level structure ───────────────────────────────────────────────────────

    public function test_standard_pipeline_produces_six_levels(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        // Level 0: [ShotValidation, Tier1]
        // Level 1: [MotionBeat, Tier2]
        // Level 2: [CameraArc, CameraValidation]
        // Level 3: [FreezeStage]
        // Level 4: [Tier3]
        // Level 5: [Backend]
        $this->assertSame(6, $plan->levelCount());
    }

    public function test_level_zero_contains_shot_validation_and_tier1(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names = $plan->levels[0]->stageNames();
        $this->assertContains('ShotValidationStage', $names);
        $this->assertContains('Tier1Stage', $names);
        $this->assertCount(2, $names);
    }

    public function test_level_one_contains_motion_beat_and_tier2(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names = $plan->levels[1]->stageNames();
        $this->assertContains('MotionBeatStage', $names);
        $this->assertContains('Tier2Stage', $names);
        $this->assertCount(2, $names);
    }

    public function test_level_two_contains_camera_arc_and_camera_validation(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names = $plan->levels[2]->stageNames();
        $this->assertContains('CameraArcStage', $names);
        $this->assertContains('CameraValidationStage', $names);
        $this->assertCount(2, $names);
    }

    public function test_level_three_contains_freeze_stage(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names = $plan->levels[3]->stageNames();
        $this->assertSame(['FreezeStage'], $names);
    }

    public function test_level_four_contains_tier3(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names = $plan->levels[4]->stageNames();
        $this->assertSame(['Tier3Stage'], $names);
    }

    public function test_level_five_contains_backend(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names = $plan->levels[5]->stageNames();
        $this->assertSame(['BackendStage'], $names);
    }

    // ── flatStages() ──────────────────────────────────────────────────────────

    public function test_flat_stages_returns_nine_in_full_mode(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $this->assertCount(9, $plan->flatStages());
    }

    public function test_flat_stages_returns_seven_in_draft_mode(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::draft());
        // ShotValidation and CameraValidation removed
        $this->assertCount(7, $plan->flatStages());
    }

    public function test_flat_stages_order_is_topologically_valid(): void
    {
        $plan   = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $names  = array_map(fn($s) => $s->name(), $plan->flatStages());
        $tier1  = array_search('Tier1Stage', $names);
        $tier2  = array_search('Tier2Stage', $names);
        $tier3  = array_search('Tier3Stage', $names);
        $backend = array_search('BackendStage', $names);

        $this->assertLessThan($tier2,  $tier1,  'Tier1 must precede Tier2');
        $this->assertLessThan($tier3,  $tier2,  'Tier2 must precede Tier3');
        $this->assertLessThan($backend, $tier3, 'Tier3 must precede Backend');
    }

    // ── Cost awareness ────────────────────────────────────────────────────────

    public function test_cost_aware_ordering_puts_cheaper_stage_first_in_level(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());

        // Level 0: ShotValidation(0.5ms) must come before Tier1(8ms)
        $level0 = $plan->levels[0]->stageNames();
        $this->assertSame('ShotValidationStage', $level0[0], 'Cheapest stage must be first in level 0');

        // Level 2: CameraValidation(0.3ms) must come before CameraArc(1.0ms)
        $level2 = $plan->levels[2]->stageNames();
        $this->assertSame('CameraValidationStage', $level2[0], 'Cheapest stage must be first in level 2');
    }

    // ── Parallel speedup ──────────────────────────────────────────────────────

    public function test_estimated_parallel_ms_less_than_sequential(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $this->assertLessThan(
            $plan->estimatedSequentialMs(),
            $plan->estimatedParallelMs(),
            'Parallel estimate must be less than sequential (pipeline has parallel levels)'
        );
    }

    public function test_parallel_speedup_greater_than_one(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $this->assertGreaterThan(1.0, $plan->parallelSpeedup());
    }

    public function test_execution_level_parallel_ms_is_max_of_stage_costs(): void
    {
        $plan   = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $level0 = $plan->levels[0]; // [ShotValidation(0.5), Tier1(8.0)]

        // max(0.5, 8.0) = 8.0
        $this->assertEqualsWithDelta(8.0, $level0->estimatedParallelMs(), 0.001);
    }

    public function test_execution_level_sequential_ms_is_sum_of_stage_costs(): void
    {
        $plan   = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $level0 = $plan->levels[0]; // [ShotValidation(0.5), Tier1(8.0)]

        // 0.5 + 8.0 = 8.5
        $this->assertEqualsWithDelta(8.5, $level0->estimatedSequentialMs(), 0.001);
    }

    // ── skipped / appliedPasses ───────────────────────────────────────────────

    public function test_full_mode_has_no_skipped_stages(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $this->assertEmpty($plan->skippedStages);
    }

    public function test_draft_mode_skips_two_validation_stages(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::draft());
        $this->assertCount(2, $plan->skippedStages);
        $this->assertContains('ShotValidationStage', $plan->skippedStages);
        $this->assertContains('CameraValidationStage', $plan->skippedStages);
    }

    public function test_applied_passes_contains_both_default_passes(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $this->assertContains('DeadStageElimination', $plan->appliedPasses);
        $this->assertContains('CostAwareOrdering',    $plan->appliedPasses);
    }

    // ── describe() ───────────────────────────────────────────────────────────

    public function test_describe_has_required_keys(): void
    {
        $plan = $this->optimizer->optimize($this->def, OptimizationContext::full());
        $desc = $plan->describe();

        $this->assertArrayHasKey('levels', $desc);
        $this->assertArrayHasKey('skipped_stages', $desc);
        $this->assertArrayHasKey('applied_passes', $desc);
        $this->assertArrayHasKey('estimated_cost', $desc);
        $this->assertArrayHasKey('estimated_parallel_ms', $desc);
        $this->assertArrayHasKey('estimated_sequential_ms', $desc);
        $this->assertArrayHasKey('parallel_speedup', $desc);
    }

    // ── Edge: single-stage pipeline ───────────────────────────────────────────

    public function test_single_stage_pipeline_has_one_level(): void
    {
        $single = PipelineDefinition::fromStages($this->def->stages()[5]); // BackendStage
        $plan   = $this->optimizer->optimize($single, OptimizationContext::full());
        $this->assertSame(1, $plan->levelCount());
        $this->assertSame(1.0, $plan->parallelSpeedup()); // single level = no speedup
    }

    // ── PassOptimizer immutability ────────────────────────────────────────────

    public function test_with_pass_returns_new_optimizer(): void
    {
        $original = PassOptimizer::defaults();
        $extended = $original->withPass($this->createMock(
            \App\Services\AI\AFOS\Passes\Optimizer\OptimizationPass::class
        ));
        $this->assertNotSame($original, $extended);
    }
}
