<?php

namespace App\Services\AI\AFOS\Benchmark;

/**
 * QAMetricEvaluator — plugin interface for Vision QA metric evaluation.
 *
 * Each evaluator handles one metric type (boolean, ratio, enum, score).
 * Custom per-metric evaluators can be registered to override the built-in
 * type-based ones (e.g. ReflectionMetricEvaluator for 'reflection_visible').
 *
 * Engine usage:
 *   foreach ($expectation->metrics as $metric) {
 *       $evaluator = $registry->for($metric);
 *       $result    = $evaluator->evaluate($detected[$metric->id] ?? null, $metric);
 *   }
 *
 * No switch-case. No if ($metric->id === 'x'). Pure plugin dispatch.
 */
interface QAMetricEvaluator
{
    /**
     * Return true if this evaluator can handle the given metric.
     * Registry checks evaluators in registration order — more specific
     * evaluators (matched by id) should be registered before generic
     * type-based ones.
     */
    public function supports(QAMetric $metric): bool;

    /**
     * Evaluate $detected against the metric spec and return a typed result.
     *
     * @param mixed   $detected  Value from Vision model (null = not yet detected)
     * @param QAMetric $spec     The metric spec with expected/min/max
     */
    public function evaluate(mixed $detected, QAMetric $spec): QAResult;
}
