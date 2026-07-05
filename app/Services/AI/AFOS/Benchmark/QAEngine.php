<?php

namespace App\Services\AI\AFOS\Benchmark;

/**
 * QAEngine — Vision QA evaluation engine.
 *
 * Evaluates all metrics for a scene expectation against detected values
 * from the Vision model. Pure plugin loop — no switch-case, no metric-id
 * branching inside the engine.
 *
 * Usage (when Vision QA is available):
 *
 *   $engine    = QAEngine::defaults();
 *   $detected  = $visionModel->analyze($videoFrame); // ['reflection_visible' => true, ...]
 *   $results   = $engine->evaluate($expectation, $detected);
 *   $score     = $engine->score($expectation, $detected); // 0.0–1.0
 *
 * Usage (now, without Vision QA — detected values are null):
 *
 *   $results = $engine->evaluate($expectation, []); // all null → all pending
 *
 * Adding a new metric type:
 *   Implement QAMetricEvaluator, register before defaults → engine unchanged.
 */
final class QAEngine
{
    public function __construct(
        private readonly MetricEvaluatorRegistry $registry,
    ) {}

    /**
     * Evaluate all metrics for one scene.
     *
     * @param array<string, mixed> $detected  keyed by metric->id; missing keys → null
     * @return QAResult[]
     */
    public function evaluate(QAExpectation $expectation, array $detected = []): array
    {
        return array_values(array_map(
            fn(QAMetric $metric) => $this->registry->for($metric)->evaluate(
                $detected[$metric->id] ?? null,
                $metric
            ),
            $expectation->metrics
        ));
    }

    /**
     * Score = passed metrics / total metrics.
     * Returns null when no metrics are defined (not 0, to distinguish "no QA" from "0% pass").
     */
    public function score(QAExpectation $expectation, array $detected = []): ?float
    {
        $results = $this->evaluate($expectation, $detected);

        if (empty($results)) {
            return null;
        }

        $passed = count(array_filter($results, fn(QAResult $r) => $r->pass));
        return $passed / count($results);
    }

    public static function defaults(): self
    {
        return new self(MetricEvaluatorRegistry::defaults());
    }
}
