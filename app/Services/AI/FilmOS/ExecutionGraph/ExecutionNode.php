<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use App\Services\AI\FilmOS\Graph\GraphNode;

/**
 * HOW a task was (or will be) executed.
 * Mutable: status, timing, retryCount thay đổi trong suốt vòng đời execution.
 *
 * Không lưu WHY — đó là việc của DecisionDAG.
 * Không lưu business output — đó là việc của DAGRuntime.
 * ExecutionNode chỉ ghi lại: task nào, chạy khi nào, tốn bao lâu, thất bại thế nào.
 */
final class ExecutionNode extends GraphNode
{
    public ExecutionNodeStatus $status      = ExecutionNodeStatus::PENDING;
    public ?float              $startedAt   = null; // Unix epoch float
    public ?float              $completedAt = null;
    public int                 $retryCount  = 0;
    public ?string             $error       = null;
    public mixed               $result      = null; // output của task handler

    public function __construct(
        string                         $id,
        public readonly string         $taskId,
        public readonly string         $description = '',
    ) {
        parent::__construct($id);
    }

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

    /** Thời gian thực thi (ms). null nếu chưa hoàn thành. */
    public function elapsedMs(): ?float
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return null;
        }
        return ($this->completedAt - $this->startedAt) * 1000.0;
    }

    public function label(): string
    {
        return "[{$this->status->value}] {$this->taskId}";
    }
}
