<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use App\Services\AI\FilmOS\Graph\Graph;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;

/**
 * HOW tasks are orchestrated — tách biệt hoàn toàn với DecisionDAG (WHY).
 *
 * DecisionDAG ghi lại: tại sao mỗi quyết định được đưa ra.
 * ExecutionGraph ghi lại: task nào chạy, theo thứ tự nào, tốn bao lâu.
 *
 * @extends Graph<ExecutionNode, ExecutionEdge>
 */
final class ExecutionGraph extends Graph
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $productionId,
        public readonly float  $createdAt = 0.0,
    ) {}

    // ── Queries ───────────────────────────────────────────────────────────────

    /** @return ExecutionNode[] */
    public function pendingNodes(): array
    {
        return $this->nodesWithStatus(ExecutionNodeStatus::PENDING);
    }

    /** @return ExecutionNode[] */
    public function runningNodes(): array
    {
        return $this->nodesWithStatus(ExecutionNodeStatus::RUNNING);
    }

    /** @return ExecutionNode[] */
    public function completedNodes(): array
    {
        return $this->nodesWithStatus(ExecutionNodeStatus::COMPLETED);
    }

    /** @return ExecutionNode[] */
    public function failedNodes(): array
    {
        return $this->nodesWithStatus(ExecutionNodeStatus::FAILED);
    }

    /** @return ExecutionNode[] */
    public function skippedNodes(): array
    {
        return $this->nodesWithStatus(ExecutionNodeStatus::SKIPPED);
    }

    /** Nodes ready to run: PENDING and all hard-dependency parents COMPLETED. */
    public function readyNodes(): array
    {
        return array_values(array_filter(
            $this->pendingNodes(),
            fn(ExecutionNode $n) => $this->allDepsCompleted($n->id),
        ));
    }

    public function isFullyCompleted(): bool
    {
        foreach ($this->nodes() as $node) {
            if ($node->status === ExecutionNodeStatus::PENDING
                || $node->status === ExecutionNodeStatus::RUNNING) {
                return false;
            }
        }
        return true;
    }

    public function hasFailures(): bool
    {
        return count($this->failedNodes()) > 0;
    }

    /**
     * Critical path: longest chain (by estimated latency) from root to sink.
     * Phase 1: returns topoSort order (all weights = 1).
     * Phase 2: weight edges by actual elapsedMs to find true bottleneck.
     *
     * @return ExecutionNode[]
     */
    public function criticalPath(): array
    {
        try {
            return GraphAlgorithms::topoSort($this);
        } catch (\RuntimeException) {
            return [];
        }
    }

    // ── Summary ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $totalMs = array_sum(array_map(
            fn(ExecutionNode $n) => $n->elapsedMs() ?? 0.0,
            $this->completedNodes(),
        ));

        return [
            'executionId' => $this->executionId,
            'productionId' => $this->productionId,
            'total'     => $this->nodeCount(),
            'completed' => count($this->completedNodes()),
            'failed'    => count($this->failedNodes()),
            'skipped'   => count($this->skippedNodes()),
            'pending'   => count($this->pendingNodes()),
            'totalMs'   => $totalMs,
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /** @return ExecutionNode[] */
    private function nodesWithStatus(ExecutionNodeStatus $status): array
    {
        return array_values(array_filter(
            $this->nodes(),
            fn(ExecutionNode $n) => $n->status === $status,
        ));
    }

    private function allDepsCompleted(string $nodeId): bool
    {
        foreach ($this->edges() as $edge) {
            if ($edge->toId !== $nodeId || !$edge->isDependency()) {
                continue;
            }
            $parent = $this->node($edge->fromId);
            if ($parent === null || !$parent->isCompleted()) {
                return false;
            }
        }
        return true;
    }
}
