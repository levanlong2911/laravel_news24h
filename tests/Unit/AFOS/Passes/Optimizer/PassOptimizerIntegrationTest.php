<?php

namespace Tests\Unit\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationPass;
use App\Services\AI\AFOS\Passes\Optimizer\PassOptimizer;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use PHPUnit\Framework\TestCase;

class PassOptimizerIntegrationTest extends TestCase
{
    private PassOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = PassOptimizer::defaults();
    }

    // ── Basic optimizer behaviour ─────────────────────────────────────────────

    public function test_defaults_returns_pass_optimizer_instance(): void
    {
        $this->assertInstanceOf(PassOptimizer::class, PassOptimizer::defaults());
    }

    public function test_optimize_full_mode_produces_execution_plan(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertNotNull($plan);
        $this->assertGreaterThan(0, $plan->levelCount());
    }

    public function test_optimize_draft_mode_produces_execution_plan(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::draft());

        $this->assertNotNull($plan);
        $this->assertGreaterThan(0, $plan->levelCount());
    }

    public function test_optimize_records_applied_passes_in_order(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertSame(
            ['DeadStageElimination', 'CostAwareOrdering'],
            $plan->appliedPasses
        );
    }

    public function test_optimize_full_mode_records_zero_skipped_stages(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertSame(0, count($plan->skippedStages));
    }

    public function test_optimize_draft_mode_records_two_skipped_stages(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::draft());

        $this->assertCount(2, $plan->skippedStages);
    }

    public function test_optimize_estimated_cost_equals_sum_of_remaining_stages(): void
    {
        $plan     = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $stages   = $plan->flatStages();
        $expected = array_sum(array_map(fn($s) => $s->metadata()->cost->estimatedMs, $stages));

        $this->assertEqualsWithDelta($expected, $plan->estimatedCost->estimatedMs, 0.001);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_optimize_is_deterministic_with_same_inputs(): void
    {
        $def  = PipelineDefinition::standard();
        $ctx  = OptimizationContext::full();
        $plan1 = $this->optimizer->optimize($def, $ctx);
        $plan2 = $this->optimizer->optimize($def, $ctx);

        $this->assertSame($plan1->appliedPasses, $plan2->appliedPasses);
        $this->assertSame($plan1->levelCount(),  $plan2->levelCount());
        $this->assertSame(
            array_map(fn($s) => $s->name(), $plan1->flatStages()),
            array_map(fn($s) => $s->name(), $plan2->flatStages()),
        );
    }

    public function test_optimize_same_inputs_produces_same_level_structure(): void
    {
        $def  = PipelineDefinition::standard();
        $plan1 = $this->optimizer->optimize($def, OptimizationContext::full());
        $plan2 = $this->optimizer->optimize($def, OptimizationContext::full());

        for ($i = 0; $i < $plan1->levelCount(); $i++) {
            $this->assertSame(
                $plan1->levels[$i]->stageNames(),
                $plan2->levels[$i]->stageNames(),
            );
        }
    }

    // ── Immutability ──────────────────────────────────────────────────────────

    public function test_with_pass_returns_new_optimizer_instance(): void
    {
        $extra    = $this->createNullPass();
        $extended = $this->optimizer->withPass($extra);

        $this->assertNotSame($this->optimizer, $extended);
    }

    public function test_with_pass_does_not_mutate_original(): void
    {
        $original  = PassOptimizer::defaults();
        $planBefore = $original->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $original->withPass($this->createNullPass());

        $planAfter = $original->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $this->assertSame($planBefore->appliedPasses, $planAfter->appliedPasses);
    }

    public function test_with_pass_appends_at_end(): void
    {
        $extra    = $this->createNamedPass('MyCustomPass');
        $extended = $this->optimizer->withPass($extra);
        $plan     = $extended->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $passes = $plan->appliedPasses;
        $this->assertSame('MyCustomPass', end($passes));
    }

    // ── Empty pipeline ────────────────────────────────────────────────────────

    public function test_empty_pipeline_produces_plan_with_no_levels(): void
    {
        $def  = PipelineDefinition::fromStages();
        $plan = $this->optimizer->optimize($def, OptimizationContext::full());

        $this->assertSame(0, $plan->levelCount());
    }

    public function test_empty_pipeline_produces_zero_stages(): void
    {
        $def  = PipelineDefinition::fromStages();
        $plan = $this->optimizer->optimize($def, OptimizationContext::full());

        $this->assertCount(0, $plan->flatStages());
    }

    public function test_empty_pipeline_estimated_cost_is_zero(): void
    {
        $def  = PipelineDefinition::fromStages();
        $plan = $this->optimizer->optimize($def, OptimizationContext::full());

        $this->assertSame(0.0, $plan->estimatedCost->estimatedMs);
    }

    // ── Single stage ──────────────────────────────────────────────────────────

    public function test_single_transform_stage_produces_one_level(): void
    {
        // BackendStage is a transform stage (not a validation stage)
        $def  = PipelineDefinition::fromStages(PipelineDefinition::standard()->stages()[5]);
        $plan = $this->optimizer->optimize($def, OptimizationContext::full());

        $this->assertSame(1, $plan->levelCount());
    }

    public function test_single_stage_in_full_mode_has_zero_skipped(): void
    {
        $def  = PipelineDefinition::fromStages(PipelineDefinition::standard()->stages()[5]);
        $plan = $this->optimizer->optimize($def, OptimizationContext::full());

        $this->assertEmpty($plan->skippedStages);
    }

    public function test_single_validation_stage_in_draft_mode_is_eliminated(): void
    {
        // ShotValidationStage is a validation stage (writes=[]) — skipped in draft
        $def  = PipelineDefinition::fromStages(PipelineDefinition::standard()->stages()[0]);
        $plan = $this->optimizer->optimize($def, OptimizationContext::draft());

        $this->assertCount(0, $plan->flatStages());
        $this->assertCount(1, $plan->skippedStages);
    }

    public function test_single_stage_pipeline_parallel_speedup_is_one(): void
    {
        $def  = PipelineDefinition::fromStages(PipelineDefinition::standard()->stages()[5]);
        $plan = $this->optimizer->optimize($def, OptimizationContext::full());

        $this->assertEqualsWithDelta(1.0, $plan->parallelSpeedup(), 0.001);
    }

    // ── Custom pass chain ─────────────────────────────────────────────────────

    public function test_custom_pass_receives_previous_pass_output(): void
    {
        $firstRemovedNames  = null;
        $secondInputNames   = null;

        $first = $this->createCallbackPass('Pass1', function (array $stages) use (&$firstRemovedNames): array {
            // Remove BackendStage
            $result = array_filter($stages, fn($s) => $s->name() !== 'BackendStage');
            $firstRemovedNames = array_map(fn($s) => $s->name(), array_values($result));
            return array_values($result);
        });

        $second = $this->createCallbackPass('Pass2', function (array $stages) use (&$secondInputNames): array {
            $secondInputNames = array_map(fn($s) => $s->name(), $stages);
            return $stages;
        });

        PassOptimizer::defaults()
            ->withPass($first)
            ->withPass($second)
            ->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertSame($firstRemovedNames, $secondInputNames, 'Second pass must receive first pass output');
    }

    public function test_no_passes_optimizer_returns_all_stages(): void
    {
        $bare = new PassOptimizer([]);
        $plan = $bare->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertCount(9, $plan->flatStages());
    }

    public function test_no_passes_optimizer_records_empty_applied_passes(): void
    {
        $bare = new PassOptimizer([]);
        $plan = $bare->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertSame([], $plan->appliedPasses);
    }

    public function test_pass_that_removes_all_stages_produces_empty_plan(): void
    {
        $wipe = $this->createCallbackPass('Wipe', fn(array $stages): array => []);
        $bare = new PassOptimizer([$wipe]);
        $plan = $bare->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertCount(0, $plan->flatStages());
        $this->assertCount(9, $plan->skippedStages);
    }

    public function test_pass_chain_accumulates_skipped_stage_names(): void
    {
        $removeBackend = $this->createCallbackPass('P1', function (array $stages): array {
            return array_values(array_filter($stages, fn($s) => $s->name() !== 'BackendStage'));
        });
        $removeTier3 = $this->createCallbackPass('P2', function (array $stages): array {
            return array_values(array_filter($stages, fn($s) => $s->name() !== 'Tier3Stage'));
        });

        $plan = (new PassOptimizer([$removeBackend, $removeTier3]))
            ->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertContains('BackendStage', $plan->skippedStages);
        $this->assertContains('Tier3Stage',  $plan->skippedStages);
    }

    // ── Side-effect stages ────────────────────────────────────────────────────

    public function test_side_effect_stage_kept_in_draft_mode(): void
    {
        // Create a mock stage with SIDE_EFFECT capability
        $sideEffectStage = $this->createSideEffectStage('AuditStage');
        $def  = PipelineDefinition::fromStages($sideEffectStage);
        $plan = $this->optimizer->optimize($def, OptimizationContext::draft());

        $this->assertContains('AuditStage', array_map(fn($s) => $s->name(), $plan->flatStages()));
    }

    public function test_side_effect_stage_not_in_skipped_list(): void
    {
        $sideEffectStage = $this->createSideEffectStage('AuditStage');
        $def  = PipelineDefinition::fromStages($sideEffectStage);
        $plan = $this->optimizer->optimize($def, OptimizationContext::draft());

        $this->assertNotContains('AuditStage', $plan->skippedStages);
    }

    public function test_side_effect_stage_mixed_with_validation_in_draft(): void
    {
        $validation     = PipelineDefinition::standard()->stages()[0]; // ShotValidationStage
        $sideEffect     = $this->createSideEffectStage('AuditStage');
        $def            = PipelineDefinition::fromStages($validation, $sideEffect);
        $plan           = $this->optimizer->optimize($def, OptimizationContext::draft());
        $remainingNames = array_map(fn($s) => $s->name(), $plan->flatStages());

        $this->assertContains('AuditStage',         $remainingNames);
        $this->assertNotContains('ShotValidationStage', $remainingNames);
    }

    // ── Budget mode ───────────────────────────────────────────────────────────

    public function test_budget_context_is_passed_through_to_passes(): void
    {
        $receivedContext = null;
        $pass = $this->createCallbackPass('Budget', function (array $stages, OptimizationContext $ctx) use (&$receivedContext): array {
            $receivedContext = $ctx;
            return $stages;
        });

        $ctx = OptimizationContext::full()->withBudgetMs(100.0);
        (new PassOptimizer([$pass]))->optimize(PipelineDefinition::standard(), $ctx);

        $this->assertNotNull($receivedContext);
        $this->assertTrue($receivedContext->exceedsBudget(
            \App\Services\AI\AFOS\Passes\Pipeline\StageCost::cpu(200.0)
        ));
    }

    public function test_context_full_mode_string(): void
    {
        $ctx = OptimizationContext::full();
        $this->assertFalse($ctx->isDraft());
    }

    public function test_context_draft_mode_string(): void
    {
        $ctx = OptimizationContext::draft();
        $this->assertTrue($ctx->isDraft());
    }

    // ── Level structure with optimizer ────────────────────────────────────────

    public function test_full_mode_six_levels_match_expected_structure(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());

        $this->assertSame(6, $plan->levelCount());
        $this->assertCount(2, $plan->levels[0]->stages); // [ShotValidation, Tier1]
        $this->assertCount(2, $plan->levels[1]->stages); // [MotionBeat, Tier2]
        $this->assertCount(2, $plan->levels[2]->stages); // [CameraValidation, CameraArc]
        $this->assertCount(1, $plan->levels[3]->stages); // [TemporalAssembly]
        $this->assertCount(1, $plan->levels[4]->stages); // [Tier3]
        $this->assertCount(1, $plan->levels[5]->stages); // [Backend]
    }

    public function test_draft_mode_has_fewer_levels_than_full(): void
    {
        $full  = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $draft = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::draft());

        $this->assertLessThanOrEqual($full->levelCount(), $draft->levelCount());
    }

    public function test_plan_flat_stages_respects_dependency_order(): void
    {
        $plan  = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $names = array_map(fn($s) => $s->name(), $plan->flatStages());

        $tier1   = array_search('Tier1Stage',   $names);
        $tier2   = array_search('Tier2Stage',   $names);
        $tier3   = array_search('Tier3Stage',   $names);
        $backend = array_search('BackendStage', $names);

        $this->assertLessThan($tier2,   $tier1,   'Tier1 before Tier2');
        $this->assertLessThan($tier3,   $tier2,   'Tier2 before Tier3');
        $this->assertLessThan($backend, $tier3,   'Tier3 before Backend');
    }

    public function test_cost_aware_ordering_puts_cheaper_stage_first_within_level(): void
    {
        $plan   = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $level0 = $plan->levels[0]->stageNames();
        $level2 = $plan->levels[2]->stageNames();

        // Level 0: ShotValidation(0.5ms) before Tier1(8ms)
        $this->assertSame('ShotValidationStage',   $level0[0]);
        // Level 2: CameraValidation(0.3ms) before Tier3(12ms)
        $this->assertSame('CameraValidationStage', $level2[0]);
    }

    public function test_level_count_reflects_remaining_stages_in_draft(): void
    {
        $plan = $this->optimizer->optimize(PipelineDefinition::standard(), OptimizationContext::draft());

        // After elimination of 2 validation stages, backend must still be reachable
        $names = array_map(fn($s) => $s->name(), $plan->flatStages());
        $this->assertContains('BackendStage', $names);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createNullPass(): OptimizationPass
    {
        return $this->createCallbackPass('Null', fn(array $s): array => $s);
    }

    private function createNamedPass(string $name): OptimizationPass
    {
        return $this->createCallbackPass($name, fn(array $s): array => $s);
    }

    private function createCallbackPass(string $name, callable $fn): OptimizationPass
    {
        return new class($name, $fn) implements OptimizationPass {
            public function __construct(
                private readonly string   $passName,
                private readonly \Closure $fn,
            ) {}

            public function optimize(array $stages, OptimizationContext $context): array
            {
                $ref = new \ReflectionFunction($this->fn);
                // Pass context if the callable accepts it as second arg
                if ($ref->getNumberOfParameters() >= 2) {
                    return ($this->fn)($stages, $context);
                }
                return ($this->fn)($stages);
            }

            public function name(): string        { return $this->passName; }
            public function description(): string { return ''; }
        };
    }

    private function createSideEffectStage(string $name): CompilerStage
    {
        return new class($name) implements CompilerStage {
            public function __construct(private readonly string $stageName) {}

            public function run(\App\Services\AI\AFOS\Passes\Pipeline\PipelineState $state): \App\Services\AI\AFOS\Passes\Pipeline\PipelineState
            {
                return $state;
            }

            public function name(): string
            {
                return $this->stageName;
            }

            public function metadata(): \App\Services\AI\AFOS\Passes\Pipeline\StageMetadata
            {
                return new \App\Services\AI\AFOS\Passes\Pipeline\StageMetadata(
                    name:         $this->stageName,
                    reads:        [],
                    writes:       [],
                    cost:         \App\Services\AI\AFOS\Passes\Pipeline\StageCost::cpu(1.0),
                    description:  'Side effect stage for testing',
                    deterministic: false,
                    cacheable:     false,
                    parallelizable: false,
                    category:      'side_effect',
                    capabilities:  [StageCapability::SIDE_EFFECT],
                );
            }
        };
    }
}
