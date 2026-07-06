<?php

namespace Tests\Unit\AFOS\Passes\Scheduler;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\PassOptimizer;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Scheduler\ParallelScheduler;
use App\Services\AI\AFOS\Passes\Scheduler\Scheduler;
use App\Services\AI\AFOS\Passes\Scheduler\SequentialScheduler;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use PHPUnit\Framework\TestCase;

class SchedulerTest extends TestCase
{
    // ── Scheduler interface ───────────────────────────────────────────────────

    public function test_sequential_scheduler_implements_scheduler(): void
    {
        $this->assertInstanceOf(Scheduler::class, new SequentialScheduler());
    }

    public function test_parallel_scheduler_implements_scheduler(): void
    {
        $this->assertInstanceOf(Scheduler::class, new ParallelScheduler());
    }

    public function test_sequential_scheduler_name_is_sequential(): void
    {
        $this->assertSame('sequential', (new SequentialScheduler())->name());
    }

    public function test_parallel_scheduler_name_is_parallel(): void
    {
        $this->assertSame('parallel', (new ParallelScheduler())->name());
    }

    // ── SequentialScheduler correctness ───────────────────────────────────────

    public function test_sequential_scheduler_produces_compiled_prompt(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = $this->makeInitialState();

        $result = (new SequentialScheduler())->execute($plan, $state);

        $this->assertNotNull($result->compiledPrompt);
        $this->assertNotEmpty($result->compiledPrompt);
    }

    public function test_sequential_scheduler_sets_composition_ir(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = (new SequentialScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotNull($state->composition);
    }

    public function test_sequential_scheduler_sets_camera_ir(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = (new SequentialScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotNull($state->camera);
    }

    public function test_sequential_scheduler_sets_prompt_ir(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = (new SequentialScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotNull($state->promptIR);
    }

    public function test_sequential_scheduler_draft_mode_still_produces_prompt(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::draft());
        $state = (new SequentialScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotEmpty($state->compiledPrompt);
    }

    // ── ParallelScheduler correctness ─────────────────────────────────────────

    public function test_parallel_scheduler_produces_compiled_prompt(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = (new ParallelScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotNull($state->compiledPrompt);
        $this->assertNotEmpty($state->compiledPrompt);
    }

    public function test_parallel_scheduler_sets_composition_ir(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = (new ParallelScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotNull($state->composition);
    }

    public function test_parallel_scheduler_sets_camera_ir(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $state = (new ParallelScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotNull($state->camera);
    }

    public function test_parallel_scheduler_draft_mode_produces_prompt(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::draft());
        $state = (new ParallelScheduler())->execute($plan, $this->makeInitialState());

        $this->assertNotEmpty($state->compiledPrompt);
    }

    // ── Equivalence: sequential == parallel ───────────────────────────────────

    public function test_sequential_and_parallel_produce_same_compiled_prompt(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::full());
        $input = $this->makeInitialState();

        $seqResult  = (new SequentialScheduler())->execute($plan, $input);
        // Re-create state for parallel (bag is shared reference; use fresh state)
        $input2     = $this->makeInitialState();
        $parResult  = (new ParallelScheduler())->execute($plan, $input2);

        $this->assertSame($seqResult->compiledPrompt, $parResult->compiledPrompt);
    }

    public function test_sequential_and_parallel_draft_produce_same_compiled_prompt(): void
    {
        $plan  = PassOptimizer::defaults()->optimize(PipelineDefinition::standard(), OptimizationContext::draft());

        $seqResult = (new SequentialScheduler())->execute($plan, $this->makeInitialState());
        $parResult = (new ParallelScheduler())->execute($plan, $this->makeInitialState());

        $this->assertSame($seqResult->compiledPrompt, $parResult->compiledPrompt);
    }

    // ── Single-stage level optimisation in ParallelScheduler ─────────────────

    public function test_parallel_scheduler_handles_single_stage_level_without_fiber(): void
    {
        // Backend stage alone: one level, one stage → no Fiber overhead path
        $single = PipelineDefinition::fromStages(PipelineDefinition::standard()->stages()[8]);
        $plan   = PassOptimizer::defaults()->optimize($single, OptimizationContext::full());

        // We still need a fully-populated state to run BackendStage
        $state = $this->makeFullState();

        $result = (new ParallelScheduler())->execute($plan, $state);
        $this->assertNotEmpty($result->compiledPrompt);
    }

    // ── AfosPassManager::withScheduler() integration ──────────────────────────

    public function test_with_scheduler_returns_new_manager(): void
    {
        $original  = AfosPassManager::defaults()->withOptimizer(PassOptimizer::defaults());
        $scheduled = $original->withScheduler(new SequentialScheduler());

        $this->assertNotSame($original, $scheduled);
    }

    public function test_with_scheduler_does_not_mutate_original(): void
    {
        $original = AfosPassManager::defaults()->withOptimizer(PassOptimizer::defaults());
        $original->withScheduler(new ParallelScheduler());

        // Original should still compile without a scheduler (uses profiling loop)
        $snap = $original->compileWithSnapshot(...$this->inputs());
        $this->assertNotEmpty($snap->artifacts->compiledPrompt);
    }

    public function test_sequential_scheduler_via_pass_manager_produces_valid_output(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults())
            ->withScheduler(new SequentialScheduler());

        $snap = $manager->compileWithSnapshot(...$this->inputs());
        $this->assertNotEmpty($snap->artifacts->compiledPrompt);
    }

    public function test_parallel_scheduler_via_pass_manager_produces_valid_output(): void
    {
        $manager = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults())
            ->withScheduler(new ParallelScheduler());

        $snap = $manager->compileWithSnapshot(...$this->inputs());
        $this->assertNotEmpty($snap->artifacts->compiledPrompt);
    }

    public function test_sequential_and_parallel_via_pass_manager_produce_same_prompt(): void
    {
        $seq = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults())
            ->withScheduler(new SequentialScheduler())
            ->compileWithSnapshot(...$this->inputs());

        $par = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults())
            ->withScheduler(new ParallelScheduler())
            ->compileWithSnapshot(...$this->inputs());

        $this->assertSame($seq->artifacts->compiledPrompt, $par->artifacts->compiledPrompt);
    }

    public function test_scheduler_path_has_plan_attached_to_snapshot(): void
    {
        $snap = AfosPassManager::defaults()
            ->withOptimizer(PassOptimizer::defaults())
            ->withScheduler(new SequentialScheduler())
            ->compileWithSnapshot(...$this->inputs());

        $this->assertNotNull($snap->executionPlan);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeInitialState(): PipelineState
    {
        [$shot, $director, $dp, $intent] = $this->inputs();
        return new PipelineState(
            new PipelineInputs($shot, $director, $dp, $intent),
            new DiagnosticBag(),
        );
    }

    private function makeFullState(): PipelineState
    {
        [$shot, $director, $dp, $intent] = $this->inputs();
        $state = new PipelineState(
            new PipelineInputs($shot, $director, $dp, $intent),
            new DiagnosticBag(),
        );
        // Run stages 0-7 to populate composition, camera, temporalPlan, and promptIR (everything BackendStage needs)
        foreach (array_slice(PipelineDefinition::standard()->stages(), 0, 8) as $stage) {
            $state = $stage->run($state);
        }
        return $state;
    }

    private function inputs(): array
    {
        return [
            ShotGoalIR::fromArray([
                'shotId'             => 'sched-test',
                'durationSec'        => 5.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'pool',
                'viewerShouldNotice' => ['pool'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'serenity',
                'energy'             => 0.5,
                'narrativeFunction'  => 'establish',
            ]),
            DirectorProfile::fromArray([
                'name'                => 'sched_dir',
                'observationWeight'   => 0.7,
                'motionWeight'        => 0.3,
                'revealWeight'        => 0.4,
                'negativeSpaceWeight' => 0.5,
                'symmetryWeight'      => 0.3,
                'cutFrequency'        => 'slow',
                'cameraPhilosophy'    => 'slow_observation',
                'colorPhilosophy'     => 'warm_golden',
            ]),
            CinematographyProfile::fromArray([
                'name'                 => 'sched_dp',
                'lensVocabularyMm'     => [35, 85],
                'lightingStyle'        => 'natural',
                'motionStyle'          => 'SLOW_PUSH',
                'depthLayersPreferred' => 3,
            ]),
            Intent::fromArray([
                'primaryEmotion'   => 'serenity',
                'secondaryEmotion' => null,
                'narrative'        => 'reveal_beauty',
                'tempo'            => 'meditative',
                'viewerExperience' => 'aspiration',
                'desiredTakeaway'  => 'Scheduler test',
            ]),
        ];
    }
}
