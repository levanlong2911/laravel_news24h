<?php

namespace App\Services\AI\AFOS\Benchmark;

/**
 * QAResult — outcome of evaluating one QAMetric against a detected value.
 *
 * Produced by QAMetricEvaluator::evaluate(). The Vision QA Engine collects
 * these and computes a per-scene score: passed / total.
 */
final class QAResult
{
    public function __construct(
        public readonly string  $metricId,
        public readonly string  $metricType,
        public readonly bool    $pass,
        public readonly mixed   $detected,
        public readonly mixed   $expected,
        public readonly string  $reason,
    ) {}

    public function toArray(): array
    {
        return [
            'metric_id' => $this->metricId,
            'type'      => $this->metricType,
            'pass'      => $this->pass,
            'detected'  => $this->detected,
            'expected'  => $this->expected,
            'reason'    => $this->reason,
        ];
    }
}
