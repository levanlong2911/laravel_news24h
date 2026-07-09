<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Snapshot\CanonicalNode;
use App\Services\AI\FilmOS\Snapshot\HashableNode;

/**
 * HOW a task was (or will be) executed.
 * Mutable: status, timing, retryCount thay đổi trong suốt vòng đời execution.
 *
 * Không lưu WHY — đó là việc của DecisionDAG.
 * Không lưu business output — đó là việc của DAGRuntime.
 * ExecutionNode chỉ ghi lại: task nào, chạy khi nào, tốn bao lâu, thất bại thế nào.
 *
 * Identity boundary:
 *   taskId      — planning-level identity ("render_F1"); stable across execution runs and replays.
 *   id          — graph node identity; equals taskId in standard use.
 *   executionId — which execution run this node belongs to ("execution-001").
 *                 Excluded from canonical hash so that topology is comparable across replays.
 */
final class ExecutionNode extends GraphNode implements HashableNode
{
    public ExecutionNodeStatus $status      = ExecutionNodeStatus::PENDING;
    public ?float              $startedAt   = null; // Unix epoch float
    public ?float              $completedAt = null;
    public int                 $retryCount  = 0;
    public ?string             $error       = null;
    public mixed               $result      = null;

    public function __construct(
        string                         $id,
        public readonly string         $taskId,
        public readonly string         $executionId = '', // which run; excluded from canonical hash
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

    /**
     * Phase B canonical contract:
     *   id   = taskId (planning-level, stable across execution runs and replays)
     *   type = status.value
     *
     * Excluded: executionId, startedAt, completedAt, elapsedMs, retryCount.
     * Two execution runs of the same plan with the same outcome MUST produce the same hash.
     */
    public function canonicalNode(): CanonicalNode
    {
        return new CanonicalNode(id: $this->taskId, type: $this->status->value);
    }
}
