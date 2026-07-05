<?php

namespace App\Services\AI\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Pipeline\StageCost;

/**
 * OptimizationContext — constraints and intent for one PassOptimizer run.
 *
 * Controls which optimizations are safe to apply and how aggressive they
 * should be. Consumed by every OptimizationPass during optimize().
 *
 * Usage:
 *   OptimizationContext::full()               // all stages, no budget limit
 *   OptimizationContext::draft()              // skip validation stages
 *   OptimizationContext::withBudgetMs(20.0)   // hint: total must fit in 20ms
 *
 * $requiredOutputs declares what the pipeline MUST produce.
 * DeadStageEliminationPass uses this to find dead stages by working backwards.
 *
 * Default: ['compiledPrompt'] — the standard pipeline terminal.
 */
final class OptimizationContext
{
    public function __construct(
        /** Maximum total estimated wall-clock ms. null = no limit. */
        public readonly ?float $budgetMs      = null,
        /** Maximum total estimated LLM tokens. null = no limit. */
        public readonly ?int   $budgetTokens  = null,
        /** Maximum total estimated API cost in USD. null = no limit. */
        public readonly ?float $budgetCostUSD = null,
        /**
         * IR FQCNs or primitive keys the pipeline must produce.
         * DeadStageElimination walks backwards from here.
         * @var string[]
         */
        public readonly array  $requiredOutputs = ['compiledPrompt'],
        /**
         * Execution mode:
         *   'full'  — all stages including validation (default, safest)
         *   'draft' — skip READ_ONLY validation stages (faster, less safe)
         */
        public readonly string $mode = 'full',
    ) {}

    // ── Factory shortcuts ─────────────────────────────────────────────────────

    public static function full(): self
    {
        return new self();
    }

    public static function draft(): self
    {
        return new self(mode: 'draft');
    }

    public static function withBudgetMs(float $ms): self
    {
        return new self(budgetMs: $ms);
    }

    // ── Budget check ──────────────────────────────────────────────────────────

    /** True if the given cost estimate exceeds any declared budget limit. */
    public function exceedsBudget(StageCost $cost): bool
    {
        if ($this->budgetMs !== null && $cost->estimatedMs > $this->budgetMs) {
            return true;
        }
        if ($this->budgetTokens !== null && $cost->estimatedTokens > $this->budgetTokens) {
            return true;
        }
        if ($this->budgetCostUSD !== null && $cost->estimatedCostUSD > $this->budgetCostUSD) {
            return true;
        }
        return false;
    }

    public function isDraft(): bool { return $this->mode === 'draft'; }
}
