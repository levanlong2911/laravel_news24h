<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * StageCost — structured cost estimate for one CompilerStage.
 *
 * Replaces the old string-based 'low' | 'medium' | 'high' cost field.
 * Consumed by the scheduler (which stage runs first), optimizer (dead-stage
 * elimination), benchmark (estimate vs actual), and future cost prediction UI.
 *
 * Factory methods:
 *   StageCost::free()                          — zero-cost (should never run?)
 *   StageCost::cpu(8.0)                        — pure CPU, O(N) in event count
 *   StageCost::constant(0.05)                  — pure CPU, O(1) regardless of graph size
 *   StageCost::model(12.0, 650, 0.0024)        — LLM call: ms, tokens, USD
 *
 * Values are estimates calibrated against observed benchmark runs.
 * The benchmark command updates these over time via --stage-timing.
 */
final class StageCost
{
    public function __construct(
        /** Expected wall-clock time in milliseconds */
        public readonly float $estimatedMs,
        /** Expected LLM prompt+completion tokens (0 for CPU-only stages) */
        public readonly int   $estimatedTokens,
        /** Expected API cost in USD (0.0 for CPU-only stages) */
        public readonly float $estimatedCostUSD,
    ) {}

    // ── Factory methods ───────────────────────────────────────────────────────

    /** Zero-cost stage (pass-through, pure read, no-op). */
    public static function free(): self
    {
        return new self(0.0, 0, 0.0);
    }

    /** CPU-bound stage with no LLM calls — cost scales with graph/event count. */
    public static function cpu(float $estimatedMs): self
    {
        return new self($estimatedMs, 0, 0.0);
    }

    /**
     * O(1) CPU stage — cost is constant regardless of graph size.
     * Use for barrier/freeze stages whose work is a single state transition,
     * not proportional to the number of events in the graph.
     */
    public static function constant(float $estimatedMs): self
    {
        return new self($estimatedMs, 0, 0.0);
    }

    /** LLM-backed stage: API latency + tokens + cost. */
    public static function model(float $estimatedMs, int $tokens, float $costUSD): self
    {
        return new self($estimatedMs, $tokens, $costUSD);
    }

    // ── Algebra ───────────────────────────────────────────────────────────────

    /** Combine two cost estimates (used by PipelineDefinition::estimatedCost()). */
    public function add(self $other): self
    {
        return new self(
            $this->estimatedMs      + $other->estimatedMs,
            $this->estimatedTokens  + $other->estimatedTokens,
            $this->estimatedCostUSD + $other->estimatedCostUSD,
        );
    }

    /** Compute accuracy ratio: estimated vs actual (1.0 = perfect). */
    public function accuracyRatio(float $actualMs): float
    {
        if ($this->estimatedMs <= 0.0) {
            return 0.0;
        }
        return round($actualMs / $this->estimatedMs, 4);
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'estimated_ms'       => $this->estimatedMs,
            'estimated_tokens'   => $this->estimatedTokens,
            'estimated_cost_usd' => $this->estimatedCostUSD,
        ];
    }
}
