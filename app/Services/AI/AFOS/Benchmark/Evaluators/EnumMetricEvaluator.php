<?php

namespace App\Services\AI\AFOS\Benchmark\Evaluators;

use App\Services\AI\AFOS\Benchmark\{QAMetric, QAMetricEvaluator, QAResult};

final class EnumMetricEvaluator implements QAMetricEvaluator
{
    public function supports(QAMetric $metric): bool
    {
        return $metric->type === 'enum';
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

        $actual = (string) $detected;
        $pass   = $actual === (string) $spec->expected;

        return new QAResult(
            metricId:   $spec->id,
            metricType: $spec->type,
            pass:       $pass,
            detected:   $actual,
            expected:   $spec->expected,
            reason:     $pass
                ? "{$spec->id}: ✓ '{$actual}'"
                : "{$spec->id}: '{$actual}' ≠ '{$spec->expected}'",
        );
    }
}
