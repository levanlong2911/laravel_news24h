<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use PHPUnit\Framework\TestCase;

class StageCostTest extends TestCase
{
    // ── Factory methods ───────────────────────────────────────────────────────

    public function test_free_has_all_zeros(): void
    {
        $cost = StageCost::free();
        $this->assertSame(0.0, $cost->estimatedMs);
        $this->assertSame(0,   $cost->estimatedTokens);
        $this->assertSame(0.0, $cost->estimatedCostUSD);
    }

    public function test_cpu_sets_ms_only(): void
    {
        $cost = StageCost::cpu(8.0);
        $this->assertSame(8.0, $cost->estimatedMs);
        $this->assertSame(0,   $cost->estimatedTokens);
        $this->assertSame(0.0, $cost->estimatedCostUSD);
    }

    public function test_model_sets_all_fields(): void
    {
        $cost = StageCost::model(12.0, 650, 0.0024);
        $this->assertSame(12.0,   $cost->estimatedMs);
        $this->assertSame(650,    $cost->estimatedTokens);
        $this->assertSame(0.0024, $cost->estimatedCostUSD);
    }

    // ── add() algebra ─────────────────────────────────────────────────────────

    public function test_add_combines_all_fields(): void
    {
        $a = StageCost::model(10.0, 400, 0.001);
        $b = StageCost::model(5.0,  200, 0.0005);
        $c = $a->add($b);

        $this->assertSame(15.0,   $c->estimatedMs);
        $this->assertSame(600,    $c->estimatedTokens);
        $this->assertEqualsWithDelta(0.0015, $c->estimatedCostUSD, 1e-9);
    }

    public function test_add_free_is_identity(): void
    {
        $cost   = StageCost::cpu(8.0);
        $result = $cost->add(StageCost::free());

        $this->assertSame(8.0, $result->estimatedMs);
        $this->assertSame(0,   $result->estimatedTokens);
        $this->assertSame(0.0, $result->estimatedCostUSD);
    }

    public function test_add_is_commutative(): void
    {
        $a = StageCost::cpu(5.0);
        $b = StageCost::cpu(3.0);

        $this->assertSame($a->add($b)->estimatedMs, $b->add($a)->estimatedMs);
    }

    public function test_add_returns_new_instance(): void
    {
        $a = StageCost::cpu(5.0);
        $b = StageCost::cpu(3.0);
        $c = $a->add($b);

        $this->assertNotSame($a, $c);
        $this->assertNotSame($b, $c);
        $this->assertSame(5.0, $a->estimatedMs); // original unchanged
    }

    // ── accuracyRatio() ───────────────────────────────────────────────────────

    public function test_accuracy_ratio_perfect_match(): void
    {
        $cost = StageCost::cpu(10.0);
        $this->assertSame(1.0, $cost->accuracyRatio(10.0));
    }

    public function test_accuracy_ratio_actual_half_of_estimate(): void
    {
        $cost = StageCost::cpu(10.0);
        $this->assertSame(0.5, $cost->accuracyRatio(5.0));
    }

    public function test_accuracy_ratio_actual_double_estimate(): void
    {
        $cost = StageCost::cpu(10.0);
        $this->assertSame(2.0, $cost->accuracyRatio(20.0));
    }

    public function test_accuracy_ratio_zero_estimate_returns_zero(): void
    {
        $cost = StageCost::free();
        $this->assertSame(0.0, $cost->accuracyRatio(5.0));
    }

    // ── toArray() ─────────────────────────────────────────────────────────────

    public function test_to_array_has_all_keys(): void
    {
        $arr = StageCost::model(12.0, 650, 0.0024)->toArray();

        $this->assertArrayHasKey('estimated_ms', $arr);
        $this->assertArrayHasKey('estimated_tokens', $arr);
        $this->assertArrayHasKey('estimated_cost_usd', $arr);
    }

    public function test_to_array_values_match_fields(): void
    {
        $cost = StageCost::model(12.0, 650, 0.0024);
        $arr  = $cost->toArray();

        $this->assertSame(12.0,   $arr['estimated_ms']);
        $this->assertSame(650,    $arr['estimated_tokens']);
        $this->assertSame(0.0024, $arr['estimated_cost_usd']);
    }

    // ── StageMetadata integration ─────────────────────────────────────────────

    public function test_all_stages_have_stage_cost_instance(): void
    {
        foreach (PipelineDefinition::standard()->stages() as $stage) {
            $this->assertInstanceOf(
                StageCost::class,
                $stage->metadata()->cost,
                "Stage '{$stage->name()}' must declare a StageCost"
            );
        }
    }

    public function test_all_stages_have_non_negative_estimated_ms(): void
    {
        foreach (PipelineDefinition::standard()->stages() as $stage) {
            $this->assertGreaterThanOrEqual(
                0.0,
                $stage->metadata()->cost->estimatedMs,
                "Stage '{$stage->name()}' estimatedMs must be >= 0"
            );
        }
    }

    public function test_stage_cost_appears_in_metadata_to_array(): void
    {
        $meta = PipelineDefinition::standard()->stages()[1]->metadata(); // Tier1Stage
        $arr  = $meta->toArray();

        $this->assertIsArray($arr['cost']);
        $this->assertArrayHasKey('estimated_ms', $arr['cost']);
        $this->assertArrayHasKey('estimated_tokens', $arr['cost']);
        $this->assertArrayHasKey('estimated_cost_usd', $arr['cost']);
    }

    // ── PipelineDefinition::estimatedCost() ───────────────────────────────────

    public function test_pipeline_estimated_cost_sums_all_stages(): void
    {
        $def  = PipelineDefinition::standard();
        $cost = $def->estimatedCost();

        // ShotValidation(0.5) + Tier1(8) + MotionBeat(1.5) + Tier2(6) + CameraArc(1.0) + CameraValidation(0.3) + FreezeStage(0.05) + Tier3(12) + Backend(2)
        $this->assertEqualsWithDelta(31.35, $cost->estimatedMs, 0.001);
        $this->assertSame(0, $cost->estimatedTokens);
        $this->assertSame(0.0, $cost->estimatedCostUSD);
    }

    public function test_pipeline_estimated_cost_returns_stage_cost_instance(): void
    {
        $cost = PipelineDefinition::standard()->estimatedCost();
        $this->assertInstanceOf(StageCost::class, $cost);
    }

    public function test_single_stage_pipeline_estimated_cost(): void
    {
        $def  = PipelineDefinition::fromStages(
            PipelineDefinition::standard()->stages()[1] // Tier1Stage: 8ms
        );
        $this->assertSame(8.0, $def->estimatedCost()->estimatedMs);
    }

    // ── CompilerMetrics integration ────────────────────────────────────────────

    public function test_snapshot_metrics_include_estimated_cost(): void
    {
        $snap    = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $metrics = $snap->metrics();

        $this->assertEqualsWithDelta(31.35, $metrics->estimatedMs, 0.001);
        $this->assertSame(0, $metrics->estimatedTokens);
        $this->assertSame(0.0, $metrics->estimatedCostUSD);
    }

    public function test_metrics_to_array_includes_estimated_fields(): void
    {
        $snap = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $arr  = $snap->metrics()->toArray();

        $this->assertArrayHasKey('estimated_ms', $arr);
        $this->assertArrayHasKey('estimated_tokens', $arr);
        $this->assertArrayHasKey('estimated_cost_usd', $arr);
        $this->assertEqualsWithDelta(31.35, $arr['estimated_ms'], 0.001);
    }

    public function test_snapshot_estimated_cost_not_in_to_array(): void
    {
        $snap = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());
        $arr  = $snap->toArray();

        $this->assertArrayNotHasKey('estimated_cost', $arr);
    }

    public function test_snapshot_has_estimated_cost_property(): void
    {
        $snap = AfosPassManager::defaults()->compileWithSnapshot(...$this->inputs());

        $this->assertNotNull($snap->estimatedCost);
        $this->assertInstanceOf(StageCost::class, $snap->estimatedCost);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function inputs(): array
    {
        return [
            \App\Services\AI\AFOS\Ir\ShotGoalIR::fromArray([
                'shotId'             => 'cost-test',
                'durationSec'        => 5.0,
                'goalType'           => 'reveal',
                'goalTarget'         => 'villa',
                'viewerShouldNotice' => ['villa'],
                'viewerShouldIgnore' => [],
                'emotion'            => 'luxury',
                'energy'             => 0.4,
                'narrativeFunction'  => 'establish',
            ]),
            \App\Services\AI\AFOS\Creative\DirectorProfile::fromArray([
                'name'                => 'cost_dir',
                'observationWeight'   => 0.7,
                'motionWeight'        => 0.3,
                'revealWeight'        => 0.5,
                'negativeSpaceWeight' => 0.4,
                'symmetryWeight'      => 0.5,
                'cutFrequency'        => 'slow',
                'cameraPhilosophy'    => 'slow_observation',
                'colorPhilosophy'     => 'warm_golden',
            ]),
            \App\Services\AI\AFOS\Creative\CinematographyProfile::fromArray([
                'name'                 => 'cost_dp',
                'lensVocabularyMm'     => [35, 85],
                'lightingStyle'        => 'natural',
                'motionStyle'          => 'SLOW_PUSH',
                'depthLayersPreferred' => 3,
            ]),
            \App\Services\AI\AFOS\Creative\Intent::fromArray([
                'primaryEmotion'   => 'luxury',
                'secondaryEmotion' => null,
                'narrative'        => 'reveal_beauty',
                'tempo'            => 'meditative',
                'viewerExperience' => 'aspiration',
                'desiredTakeaway'  => 'Cost test',
            ]),
        ];
    }
}
