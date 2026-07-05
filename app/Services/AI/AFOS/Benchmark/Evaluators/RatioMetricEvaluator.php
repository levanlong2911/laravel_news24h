<?php

namespace App\Services\AI\AFOS\Benchmark\Evaluators;

use App\Services\AI\AFOS\Benchmark\{QAMetric, QAMetricEvaluator, QAResult};

final class RatioMetricEvaluator implements QAMetricEvaluator
{
    public function supports(QAMetric $metric): bool
    {
        return $metric->type === 'ratio';
    }

    public function evaluate(mixed $detected, QAMetric $spec): QAResult
    {
        $range = $spec->min !== null && $spec->max !== null
            ? "[{$spec->min}, {$spec->max}]"
            : ($spec->min !== null ? "≥{$spec->min}" : "≤{$spec->max}");

        if ($detected === null) {
            return new QAResult(
                metricId:   $spec->id,
                metricType: $spec->type,
                pass:       false,
                detected:   null,
                expected:   $range,
                reason:     "{$spec->id}: not detected (Vision QA pending)",
            );
        }

        $value = (float) $detected;
        $pass  = ($spec->min === null || $value >= $spec->min)
              && ($spec->max === null || $value <= $spec->max);

        return new QAResult(
            metricId:   $spec->id,
            metricType: $spec->type,
            pass:       $pass,
            detected:   $value,
            expected:   $range,
            reason:     $pass
                ? "{$spec->id}: ✓ {$value} in {$range}"
                : "{$spec->id}: {$value} outside {$range}",
        );
    }
}
