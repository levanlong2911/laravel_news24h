<?php

namespace Tests\Unit\AFOS\Benchmark;

use App\Services\AI\AFOS\Benchmark\Evaluators\BooleanMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\Evaluators\EnumMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\Evaluators\RatioMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\Evaluators\ScoreMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\MetricEvaluatorRegistry;
use App\Services\AI\AFOS\Benchmark\QAMetric;
use App\Services\AI\AFOS\Benchmark\QAMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\QAResult;
use PHPUnit\Framework\TestCase;

class MetricEvaluatorRegistryTest extends TestCase
{
    private function metric(string $type, string $id = 'test_metric'): QAMetric
    {
        return new QAMetric(id: $id, type: $type, expected: true);
    }

    public function test_defaults_resolves_boolean_type(): void
    {
        $registry  = MetricEvaluatorRegistry::defaults();
        $evaluator = $registry->for($this->metric('boolean'));

        $this->assertInstanceOf(BooleanMetricEvaluator::class, $evaluator);
    }

    public function test_defaults_resolves_ratio_type(): void
    {
        $evaluator = MetricEvaluatorRegistry::defaults()->for(
            new QAMetric(id: 'x', type: 'ratio', min: 0.3, max: 0.8)
        );

        $this->assertInstanceOf(RatioMetricEvaluator::class, $evaluator);
    }

    public function test_defaults_resolves_enum_type(): void
    {
        $evaluator = MetricEvaluatorRegistry::defaults()->for(
            new QAMetric(id: 'x', type: 'enum', expected: 'golden_hour')
        );

        $this->assertInstanceOf(EnumMetricEvaluator::class, $evaluator);
    }

    public function test_defaults_resolves_score_type(): void
    {
        $evaluator = MetricEvaluatorRegistry::defaults()->for(
            new QAMetric(id: 'x', type: 'score', min: 0.7)
        );

        $this->assertInstanceOf(ScoreMetricEvaluator::class, $evaluator);
    }

    public function test_register_by_id_overrides_type_dispatch(): void
    {
        $custom = new class implements QAMetricEvaluator {
            public function supports(QAMetric $metric): bool { return false; }
            public function evaluate(mixed $detected, QAMetric $spec): QAResult {
                return new QAResult($spec->id, $spec->type, true, true, true, 'custom');
            }
        };

        $registry  = MetricEvaluatorRegistry::defaults()->registerById('reflection_visible', $custom);
        $evaluator = $registry->for(new QAMetric(id: 'reflection_visible', type: 'boolean', expected: true));

        $this->assertSame($custom, $evaluator);
    }

    public function test_id_dispatch_takes_priority_over_type(): void
    {
        $idEvaluator = new BooleanMetricEvaluator();
        $registry    = (new MetricEvaluatorRegistry())
            ->registerByType('boolean', new EnumMetricEvaluator())
            ->registerById('my_bool_metric', $idEvaluator);

        $resolved = $registry->for(new QAMetric(id: 'my_bool_metric', type: 'boolean', expected: true));

        $this->assertSame($idEvaluator, $resolved);
    }

    public function test_plugin_loop_serves_as_fallback(): void
    {
        $plugin = new class implements QAMetricEvaluator {
            public function supports(QAMetric $metric): bool { return $metric->type === 'custom_type'; }
            public function evaluate(mixed $detected, QAMetric $spec): QAResult {
                return new QAResult($spec->id, $spec->type, true, null, null, 'plugin');
            }
        };

        $registry  = MetricEvaluatorRegistry::defaults()->register($plugin);
        $evaluator = $registry->for(new QAMetric(id: 'x', type: 'custom_type'));

        $this->assertSame($plugin, $evaluator);
    }

    public function test_unknown_type_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/unknown_type/");

        MetricEvaluatorRegistry::defaults()->for(new QAMetric(id: 'x', type: 'unknown_type'));
    }

    public function test_register_methods_are_immutable(): void
    {
        $original   = MetricEvaluatorRegistry::defaults();
        $withPlugin = $original->register(new BooleanMetricEvaluator());

        // original should still throw for unknown; withPlugin only adds a plugin
        // The plugin supports boolean, but original typeMap already has it — no change expected
        $this->assertNotSame($original, $withPlugin);
    }
}
