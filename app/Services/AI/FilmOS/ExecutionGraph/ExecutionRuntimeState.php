<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Immutable runtime state for a single task within an execution run.
 *
 * Separation of concerns (ADR-016 Phase B / Phase D):
 *   ExecutionNode         — topology + identity (taskId, executionId, edges)
 *   ExecutionRuntimeState — all mutable runtime data; immutable in Phase D
 *
 * ExecutionLayerBuilder reads ONLY from ExecutionRuntimeState — never from
 * ExecutionNode mutable fields.
 *
 * Phase D: every state transition returns a NEW instance. This makes the
 * history auditable and allows execution branches to diverge cleanly.
 *
 *   $next = $state->transitionTo(RUNNING, ['startedAt' => microtime(true)]);
 *   $done = $next->transitionTo(COMPLETED, ['result' => $output, 'completedAt' => microtime(true)])
 *                ->withAttempt(COMPLETED);   // appends 'completed' to retryHistory
 *
 * Migration note:
 *   ExecutionNode still holds duplicate mutable fields (status, retryCount, etc.)
 *   to keep ExecutionGraph queries (completedNodes(), readyNodes()) working.
 *   Those fields will be removed once ExecutionGraph queries accept an
 *   ExecutionRuntimeState map instead.
 */
final class ExecutionRuntimeState
{
    /**
     * @param  string[]  $retryHistory  outcome of each attempt: 'completed' | 'failed'
     */
    public function __construct(
        public readonly ExecutionNodeStatus $status       = ExecutionNodeStatus::PENDING,
        public readonly ?float              $startedAt    = null,
        public readonly ?float              $completedAt  = null,
        public readonly ?string             $error        = null,
        public readonly mixed               $result       = null,
        public readonly array               $retryHistory = [],
    ) {}

    // ── State predicates ──────────────────────────────────────────────────────

    public function isCompleted(): bool { return $this->status === ExecutionNodeStatus::COMPLETED; }
    public function isFailed(): bool    { return $this->status === ExecutionNodeStatus::FAILED; }
    public function isPending(): bool   { return $this->status === ExecutionNodeStatus::PENDING; }
    public function isSkipped(): bool   { return $this->status === ExecutionNodeStatus::SKIPPED; }

    // ── Immutable transitions ─────────────────────────────────────────────────

    /**
     * Return a new state with $newStatus and any additional field overrides.
     *
     * Enforces a strict state machine — illegal transitions throw immediately so
     * ExecutionRuntime bugs surface at the point of the bad call, not later in
     * a corrupted checkpoint or a wrong retrySequenceHash.
     *
     * Valid transitions:
     *   PENDING   → RUNNING, SKIPPED
     *   RUNNING   → RUNNING (re-execute interrupted checkpoint), COMPLETED, FAILED
     *   FAILED    → RUNNING  (retry after failure)
     *   COMPLETED → (none — terminal)
     *   SKIPPED   → (none — terminal)
     *
     * Supported override keys: startedAt, completedAt, error, result, retryHistory.
     * Uses array_key_exists so that passing 'error' => null explicitly clears the
     * field (useful when clearing error on retry — the ?? operator would fall through).
     */
    public function transitionTo(ExecutionNodeStatus $newStatus, array $overrides = []): self
    {
        $valid = match ($this->status) {
            ExecutionNodeStatus::PENDING   => [ExecutionNodeStatus::RUNNING, ExecutionNodeStatus::SKIPPED],
            ExecutionNodeStatus::RUNNING   => [ExecutionNodeStatus::RUNNING, ExecutionNodeStatus::COMPLETED, ExecutionNodeStatus::FAILED],
            ExecutionNodeStatus::FAILED    => [ExecutionNodeStatus::RUNNING],
            ExecutionNodeStatus::COMPLETED,
            ExecutionNodeStatus::SKIPPED   => [],
        };

        if (!in_array($newStatus, $valid, true)) {
            throw new InvalidStateTransitionException($this->status, $newStatus, $valid);
        }

        return new self(
            status:       $newStatus,
            startedAt:    array_key_exists('startedAt',    $overrides) ? $overrides['startedAt']    : $this->startedAt,
            completedAt:  array_key_exists('completedAt',  $overrides) ? $overrides['completedAt']  : $this->completedAt,
            error:        array_key_exists('error',        $overrides) ? $overrides['error']        : $this->error,
            result:       array_key_exists('result',       $overrides) ? $overrides['result']       : $this->result,
            retryHistory: array_key_exists('retryHistory', $overrides) ? $overrides['retryHistory'] : $this->retryHistory,
        );
    }

    /**
     * Return a new state with $outcome appended to retryHistory.
     * Only COMPLETED and FAILED are valid outcomes — SKIPPED and PENDING are not attempts.
     *
     * Example:
     *   $s1 = $s0->withAttempt(FAILED);     // ['failed']
     *   $s2 = $s1->withAttempt(COMPLETED);  // ['failed', 'completed']
     */
    public function withAttempt(ExecutionNodeStatus $outcome): self
    {
        if (!in_array($outcome, [ExecutionNodeStatus::COMPLETED, ExecutionNodeStatus::FAILED], true)) {
            throw new \LogicException(
                "withAttempt() accepts only COMPLETED or FAILED, got: {$outcome->value}."
            );
        }

        return new self(
            status:       $this->status,
            startedAt:    $this->startedAt,
            completedAt:  $this->completedAt,
            error:        $this->error,
            result:       $this->result,
            retryHistory: [...$this->retryHistory, $outcome->value],
        );
    }

    // ── Checkpoint resume ─────────────────────────────────────────────────────

    /**
     * Rebuild an ExecutionRuntimeState from a loaded ExecutionNode.
     * Used only when resuming from checkpoint where retryHistory was not persisted.
     *
     * retryHistory is approximated: [failed × retryCount, finalStatus].
     * Correct for the standard retry pattern; diverges if circuit-breakers or
     * manual retries were involved — a known limitation of the approximation.
     */
    public static function fromNode(ExecutionNode $node): self
    {
        $retryHistory = match ($node->status) {
            ExecutionNodeStatus::COMPLETED =>
                [...array_fill(0, $node->retryCount, 'failed'), 'completed'],
            ExecutionNodeStatus::FAILED =>
                array_fill(0, $node->retryCount + 1, 'failed'),
            default => [],
        };

        return new self(
            status:       $node->status,
            startedAt:    $node->startedAt,
            completedAt:  $node->completedAt,
            error:        $node->error,
            result:       $node->result,
            retryHistory: $retryHistory,
        );
    }
}
