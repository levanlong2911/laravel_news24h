<?php

namespace Tests\Unit\AFOS\Benchmark;

use App\Services\AI\AFOS\Benchmark\QAEngine;
use App\Services\AI\AFOS\Benchmark\QAExpectation;
use App\Services\AI\AFOS\Benchmark\QAMetric;
use PHPUnit\Framework\TestCase;

class QAEngineTest extends TestCase
{
    private function expectation(array $metrics): QAExpectation
    {
        return new QAExpectation('test_scene', $metrics);
    }

    public function test_empty_expectation_returns_empty_results(): void
    {
        $engine  = QAEngine::defaults();
        $results = $engine->evaluate($this->expectation([]), []);

        $this->assertEmpty($results);
    }

    public function test_score_returns_null_for_empty_expectation(): void
    {
        $this->assertNull(QAEngine::defaults()->score($this->expectation([])));
    }

    public function test_null_detected_produces_pending_result_with_false_pass(): void
    {
        $engine    = QAEngine::defaults();
        $metric    = new QAMetric(id: 'reflection_visible', type: 'boolean', expected: true);
        $results   = $engine->evaluate($this->expectation([$metric]), []);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->pass);
        $this->assertNull($results[0]->detected);
        $this->assertStringContainsString('pending', $results[0]->reason);
    }

    public function test_boolean_true_detected_passes(): void
    {
        $engine  = QAEngine::defaults();
        $metric  = new QAMetric(id: 'reflection_visible', type: 'boolean', expected: true);
        $results = $engine->evaluate($this->expectation([$metric]), ['reflection_visible' => true]);

        $this->assertTrue($results[0]->pass);
        $this->assertSame('reflection_visible', $results[0]->metricId);
    }

    public function test_boolean_mismatch_fails(): void
    {
        $engine  = QAEngine::defaults();
        $metric  = new QAMetric(id: 'sky_visible', type: 'boolean', expected: false);
        $results = $engine->evaluate($this->expectation([$metric]), ['sky_visible' => true]);

        $this->assertFalse($results[0]->pass);
    }

    public function test_ratio_within_range_passes(): void
    {
        $engine  = QAEngine::defaults();
        $metric  = new QAMetric(id: 'water_coverage', type: 'ratio', min: 0.3, max: 0.8);
        $results = $engine->evaluate($this->expectation([$metric]), ['water_coverage' => 0.5]);

        $this->assertTrue($results[0]->pass);
    }

    public function test_ratio_below_min_fails(): void
    {
        $engine  = QAEngine::defaults();
        $metric  = new QAMetric(id: 'water_coverage', type: 'ratio', min: 0.5, max: 0.9);
        $results = $engine->evaluate($this->expectation([$metric]), ['water_coverage' => 0.2]);

        $this->assertFalse($results[0]->pass);
    }

    public function test_enum_match_passes(): void
    {
        $engine  = QAEngine::defaults();
        $metric  = new QAMetric(id: 'lighting_quality', type: 'enum', expected: 'golden_hour');
        $results = $engine->evaluate($this->expectation([$metric]), ['lighting_quality' => 'golden_hour']);

        $this->assertTrue($results[0]->pass);
    }

    public function test_enum_mismatch_fails(): void
    {
        $engine  = QAEngine::defaults();
        $metric  = new QAMetric(id: 'lighting_quality', type: 'enum', expected: 'golden_hour');
        $results = $engine->evaluate($this->expectation([$metric]), ['lighting_quality' => 'overcast']);

        $this->assertFalse($results[0]->pass);
    }

    public function test_score_is_ratio_of_passed_metrics(): void
    {
        $engine  = QAEngine::defaults();
        $metrics = [
            new QAMetric(id: 'a', type: 'boolean', expected: true),
            new QAMetric(id: 'b', type: 'boolean', expected: true),
            new QAMetric(id: 'c', type: 'boolean', expected: true),
        ];

        // a=pass, b=fail (wrong value), c=null (pending)
        $detected = ['a' => true, 'b' => false];
        $score    = $engine->score($this->expectation($metrics), $detected);

        // Only a passes (1/3)
        $this->assertEqualsWithDelta(1 / 3, $score, 0.001);
    }

    public function test_score_all_pass_returns_one(): void
    {
        $engine  = QAEngine::defaults();
        $metrics = [
            new QAMetric(id: 'a', type: 'boolean', expected: true),
            new QAMetric(id: 'b', type: 'boolean', expected: false),
        ];

        $score = $engine->score($this->expectation($metrics), ['a' => true, 'b' => false]);

        $this->assertEqualsWithDelta(1.0, $score, 0.001);
    }

    public function test_results_preserve_metric_order(): void
    {
        $engine  = QAEngine::defaults();
        $metrics = [
            new QAMetric(id: 'first',  type: 'boolean', expected: true),
            new QAMetric(id: 'second', type: 'boolean', expected: true),
            new QAMetric(id: 'third',  type: 'boolean', expected: true),
        ];

        $results = $engine->evaluate($this->expectation($metrics), []);

        $this->assertSame('first',  $results[0]->metricId);
        $this->assertSame('second', $results[1]->metricId);
        $this->assertSame('third',  $results[2]->metricId);
    }
}
