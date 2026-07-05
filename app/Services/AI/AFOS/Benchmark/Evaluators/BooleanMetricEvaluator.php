<?php

namespace App\Services\AI\AFOS\Benchmark\Evaluators;

use App\Services\AI\AFOS\Benchmark\{QAMetric, QAMetricEvaluator, QAResult};

final class BooleanMetricEvaluator implements QAMetricEvaluator
{
    public function supports(QAMetric $metric): bool
    {
        return $metric->type === 'boolean';
    }

    public function evaluate(mixed $detected, QAMetric $spec): QAResult
    {
        if ($detected === null) {
            return new QAResult(
                metricId:   $spec->id,
                metricType: $spec->type,
                pass:       false,
                detected:   null,
                expected:   $spec->expected,
                reason:     "{$spec->id}: not detected (Vision QA pending)",
            );
        }

        $expected = (bool) $spec->expected;
        $actual   = (bool) $detected;
        $pass     = $actual === $expected;

        return new QAResult(
            metricId:   $spec->id,
            metricType: $spec->type,
            pass:       $pass,
            detected:   $detected,
            expected:   $spec->expected,
            reason:     $pass
                ? "{$spec->id}: ✓ detected=" . ($actual ? 'true' : 'false')
                : "{$spec->id}: detected=" . ($actual ? 'true' : 'false') . " expected=" . ($expected ? 'true' : 'false'),
        );
    }
}
