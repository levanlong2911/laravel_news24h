<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Kết quả sau một lần chạy ExecutionRuntime.
 * Bất biến sau khi được tạo ra.
 *
 * Bao gồm ExecutionMetrics để MetaPlanner và Learning
 * có dữ liệu thực tế thay vì chỉ dựa trên mô hình.
 */
final class ExecutionResult
{
    public function __construct(
        public readonly ExecutionGraph  $graph,
        public readonly float           $totalElapsedMs,
        /** @var string[] node IDs thực sự được chạy trong lần này */
        public readonly array           $executedNodeIds,
        /** @var string[] node IDs bị bỏ qua (đã COMPLETED hoặc dep FAILED) */
        public readonly array           $skippedNodeIds,
        public readonly bool            $resumedFromCheckpoint,
        public readonly ExecutionMetrics $metrics = new ExecutionMetrics(),
    ) {}

    public function isFullyCompleted(): bool
    {
        return $this->graph->isFullyCompleted() && !$this->graph->hasFailures();
    }

    public function hasFailures(): bool
    {
        return $this->graph->hasFailures();
    }

    /** @return ExecutionNode[] */
    public function failedNodes(): array
    {
        return $this->graph->failedNodes();
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return array_merge($this->graph->summary(), [
            'totalElapsedMs'        => $this->totalElapsedMs,
            'executedCount'         => count($this->executedNodeIds),
            'skippedFromCheckpoint' => count($this->skippedNodeIds),
            'resumedFromCheckpoint' => $this->resumedFromCheckpoint,
            'metrics'               => $this->metrics->toArray(),
        ]);
    }
}
