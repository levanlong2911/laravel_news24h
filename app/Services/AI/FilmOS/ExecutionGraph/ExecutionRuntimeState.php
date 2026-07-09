<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Mutable runtime state for a single task within an execution run.
 *
 * Separation of concerns (ADR-016 Phase B):
 *   ExecutionNode  — topology + identity (taskId, executionId, edges)
 *   ExecutionRuntimeState — everything that changes during execution
 *
 * ExecutionLayerBuilder reads ONLY from ExecutionRuntimeState.
 * It never reaches into ExecutionNode for status, timing, or retry data.
 *
 * retryHistory stores the outcome of EACH ATTEMPT directly — not derived.
 * Example: ['failed', 'failed', 'completed'] means 2 retries, then success.
 * This avoids the fragile reconstruction from retryCount + status that breaks
 * when circuit breakers, cancellations, or manual retries are added.
 *
 * Migration note:
 *   ExecutionNode still holds duplicate mutable fields (status, retryCount, etc.)
 *   to keep ExecutionGraph queries (completedNodes(), readyNodes()) working.
 *   Those fields will be removed once ExecutionGraph queries are refactored
 *   to accept an ExecutionRuntimeState map instead.
 */
final class ExecutionRuntimeState
{
    public ExecutionNodeStatus $status      = ExecutionNodeStatus::PENDING;
    public ?float              $startedAt   = null;
    public ?float              $completedAt = null;
    public ?string             $error       = null;
    public mixed               $result      = null;

    /**
     * Direct record of each attempt's terminal outcome.
     * Values are ExecutionNodeStatus::value strings: 'completed' | 'failed'.
     * Written by ExecutionRuntime::recordAttempt() — NOT reconstructed.
     *
     * @var string[]
     */
    public array $retryHistory = [];

    // ── State helpers ─────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === ExecutionNodeStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === ExecutionNodeStatus::FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === ExecutionNodeStatus::PENDING;
    }

    public function isSkipped(): bool
    {
        return $this->status === ExecutionNodeStatus::SKIPPED;
    }

    // ── Recording ─────────────────────────────────────────────────────────────

    /**
     * Log the outcome of one attempt.
     * Call this for COMPLETED and FAILED outcomes — not for SKIPPED/PENDING.
     */
    public function recordAttempt(ExecutionNodeStatus $outcome): void
    {
        $this->retryHistory[] = $outcome->value;
    }

    // ── Reconstruction (checkpoint resume only) ───────────────────────────────

    /**
     * Rebuild an ExecutionRuntimeState from a loaded ExecutionNode.
     * Used only when resuming from checkpoint where retryHistory was not persisted.
     *
     * retryHistory is approximated: [failed × retryCount, finalStatus].
     * This is correct for the standard retry pattern (all retries fail until success).
     */
    public static function fromNode(ExecutionNode $node): self
    {
        $state = new self();
        $state->status      = $node->status;
        $state->startedAt   = $node->startedAt;
        $state->completedAt = $node->completedAt;
        $state->error       = $node->error;
        $state->result      = $node->result;

        // Approximate retryHistory from retryCount + status
        $state->retryHistory = match ($node->status) {
            ExecutionNodeStatus::COMPLETED =>
                [...array_fill(0, $node->retryCount, 'failed'), 'completed'],
            ExecutionNodeStatus::FAILED =>
                array_fill(0, $node->retryCount + 1, 'failed'),
            default => [],
        };

        return $state;
    }
}
