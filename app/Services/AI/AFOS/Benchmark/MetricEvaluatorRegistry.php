<?php

namespace App\Services\AI\AFOS\Benchmark;

use App\Services\AI\AFOS\Benchmark\Evaluators\BooleanMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\Evaluators\EnumMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\Evaluators\RatioMetricEvaluator;
use App\Services\AI\AFOS\Benchmark\Evaluators\ScoreMetricEvaluator;

/**
 * MetricEvaluatorRegistry — O(1) dispatch for Vision QA metric evaluators.
 *
 * Resolution order:
 *   1. idMap[metric.id]   — exact id match, registered via registerById()
 *   2. typeMap[metric.type] — type match, registered via registerByType()
 *   3. plugins[]           — supports() loop, registered via register()
 *   4. RuntimeException
 *
 * QAEngine only calls $registry->for($metric) — never inspects metric types directly.
 */
final class MetricEvaluatorRegistry
{
    /** @var array<string, QAMetricEvaluator> id → evaluator (highest priority) */
    private array $idMap = [];

    /** @var array<string, QAMetricEvaluator> type → evaluator */
    private array $typeMap = [];

    /** @var QAMetricEvaluator[] supports()-loop fallback for custom evaluators */
    private array $plugins = [];

    /** Register an evaluator for an exact metric id (overrides type-based dispatch). */
    public function registerById(string $id, QAMetricEvaluator $evaluator): self
    {
        $clone              = clone $this;
        $clone->idMap[$id]  = $evaluator;
        return $clone;
    }

    /** Register an evaluator for a metric type string. */
    public function registerByType(string $type, QAMetricEvaluator $evaluator): self
    {
        $clone                = clone $this;
        $clone->typeMap[$type] = $evaluator;
        return $clone;
    }

    /**
     * Register a plugin evaluator using supports() dispatch (O(n) fallback).
     * Use this for complex, multi-criteria evaluators that cannot map to a single type.
     */
    public function register(QAMetricEvaluator $evaluator): self
    {
        $clone            = clone $this;
        $clone->plugins[] = $evaluator;
        return $clone;
    }

    /**
     * Resolve the evaluator for a metric.
     *
     * @throws \RuntimeException if no evaluator matches
     */
    public function for(QAMetric $metric): QAMetricEvaluator
    {
        if (isset($this->idMap[$metric->id])) {
            return $this->idMap[$metric->id];
        }

        if (isset($this->typeMap[$metric->type])) {
            return $this->typeMap[$metric->type];
        }

        foreach ($this->plugins as $plugin) {
            if ($plugin->supports($metric)) {
                return $plugin;
            }
        }

        throw new \RuntimeException(
            "No evaluator registered for metric type='{$metric->type}' id='{$metric->id}'. " .
            "Call registerByType() or registerById() to add one."
        );
    }

    /** Build a registry with all built-in type-based evaluators pre-registered. */
    public static function defaults(): self
    {
        return (new self())
            ->registerByType('boolean', new BooleanMetricEvaluator())
            ->registerByType('ratio',   new RatioMetricEvaluator())
            ->registerByType('enum',    new EnumMetricEvaluator())
            ->registerByType('score',   new ScoreMetricEvaluator());
    }
}
