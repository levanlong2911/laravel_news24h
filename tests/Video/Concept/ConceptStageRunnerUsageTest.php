<?php

namespace Tests\Video\Concept;

use App\Services\Video\ConceptStageRunner;
use App\Services\Video\PlanningStageStore;
use App\Services\VideoRenderPlanService;
use App\Video\Concept\ClaudeConceptDesigner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ConceptStageRunnerUsageTest extends TestCase
{
    /** @param array<string, mixed>|null $totals */
    private function usageOf(?array $totals): array
    {
        $renderPlan = $this->createMock(VideoRenderPlanService::class);
        $renderPlan->method('lastUsage')->willReturn($totals);

        $runner = new ConceptStageRunner($this->createMock(PlanningStageStore::class), $renderPlan);

        $method = new ReflectionMethod(ConceptStageRunner::class, 'usage');
        $method->setAccessible(true);

        return $method->invoke($runner);
    }

    public function test_the_stage_records_the_alias_the_designer_actually_asks_for(): void
    {
        $usage = $this->usageOf([
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_usd' => 0.01,
            'provider_model' => 'claude-sonnet-5',
        ]);

        $this->assertSame(ClaudeConceptDesigner::MODEL, $usage['model']);
        $this->assertSame('sonnet5', $usage['model']);
        $this->assertNotSame('sonnet', $usage['model']);
    }

    public function test_the_measured_provider_model_travels_beside_the_alias(): void
    {
        $usage = $this->usageOf([
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_usd' => 0.01,
            'provider_model' => 'claude-sonnet-5',
        ]);

        $this->assertSame('claude-sonnet-5', $usage['provider_model']);
        $this->assertSame(100, $usage['tokens_in']);
        $this->assertSame(50, $usage['tokens_out']);
    }

    public function test_a_run_that_measured_nothing_reports_no_provider_model(): void
    {
        $usage = $this->usageOf(null);

        $this->assertNull($usage['provider_model']);
        $this->assertSame('sonnet5', $usage['model']);
        $this->assertSame(0, $usage['tokens_in']);
    }

    public function test_the_thinking_measurement_reaches_the_stage_usage(): void
    {
        $usage = $this->usageOf([
            'tokens_in' => 2700,
            'tokens_out' => 2500,
            'thinking_tokens' => 2500,
            'cost_usd' => 0.0304,
            'provider_model' => 'claude-sonnet-5',
        ]);

        $this->assertSame(2500, $usage['thinking_tokens']);
    }

    public function test_a_run_that_measured_nothing_reports_zero_thinking(): void
    {
        $this->assertSame(0, $this->usageOf(null)['thinking_tokens']);
    }
}
