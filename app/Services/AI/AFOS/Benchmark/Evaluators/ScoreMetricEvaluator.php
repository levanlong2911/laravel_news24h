<?php

namespace App\Services\AI\AFOS\Benchmark\Evaluators;

use App\Services\AI\AFOS\Benchmark\{QAMetric, QAMetricEvaluator, QAResult};

final class ScoreMetricEvaluator implements QAMetricEvaluator
{
    public function supports(QAMetric $metric): bool
    {
        return $metric->type === 'score';
    }

    public function evaluate(mixed $detected, QAMetric $spec): QAResult
    {
        $threshold = $spec->min !== null && $spec->max !== null
            ? "[{$spec->min}, {$spec->max}]"
            : ($spec->min !== null ? "≥{$spec->min}" : "≤{$spec->max}");

        if ($detected === null) {
            return new QAResult(
                metricId:   $spec->id,
                metricType: $spec->type,
                pass:       false,
                detected:   null,
                expected:   $threshold,
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
            expected:   $threshold,
            reason:     $pass
                ? "{$spec->id}: ✓ score {$value} {$threshold}"
                : "{$spec->id}: score {$value} fails {$threshold}",
        );
    }
}
