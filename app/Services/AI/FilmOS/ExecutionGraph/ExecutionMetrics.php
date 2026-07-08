<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Production telemetry metrics cho một execution session.
 *
 * Được thu thập bởi ExecutionRuntime trong suốt quá trình chạy.
 * MetaPlanner và PredictiveLearning có thể dùng dữ liệu này để:
 *   - Chọn provider tốt hơn (dựa trên providerFailureRate)
 *   - Điều chỉnh retry budget (dựa trên meanRetryDelayMs)
 *   - Ưu tiên critical path (dựa trên criticalPathMs)
 *   - Phát hiện bottleneck (dựa trên idleTimeMs)
 *
 * Mutable trong quá trình execution, readonly sau khi hoàn thành.
 */
final class ExecutionMetrics
{
    /** Tổng số lần retry (FAILED → re-run) */
    public int $retryCount = 0;

    /** Số lần execution bị rollback (checkpoint loaded) */
    public int $rollbackCount = 0;

    /** Trung bình thời gian giữa failure và retry (ms) */
    public float $meanRetryDelayMs = 0.0;

    /** Số lần checkpoint được ghi */
    public int $checkpointCount = 0;

    /** Kích thước trung bình của checkpoint (bytes) */
    public int $checkpointSizeBytes = 0;

    /** Số node COMPLETED */
    public int $completedCount = 0;

    /** Số node FAILED */
    public int $failedCount = 0;

    /** Số node SKIPPED */
    public int $skippedCount = 0;

    /** Tổng thời gian execution (ms) */
    public float $totalElapsedMs = 0.0;

    /** Thời gian của critical path (longest chain) */
    public float $criticalPathMs = 0.0;

    /**
     * Idle time: khoảng thời gian không node nào đang chạy.
     * Cao → scheduler đang đợi dependency, hoặc có sequential bottleneck.
     */
    public float $idleTimeMs = 0.0;

    /**
     * Provider failure count by provider name.
     * Ví dụ: ['kling' => 3, 'runway' => 0]
     * @var array<string, int>
     */
    public array $providerFailures = [];

    /**
     * Per-node timing (nodeId → elapsedMs).
     * Dùng để tính critical path sau khi execution xong.
     * @var array<string, float>
     */
    public array $nodeTiming = [];

    // ── Derived ───────────────────────────────────────────────────────────────

    public function successRate(): float
    {
        $total = $this->completedCount + $this->failedCount;
        return $total > 0 ? $this->completedCount / $total : 0.0;
    }

    public function retrySuccessRate(): float
    {
        return $this->retryCount > 0
            ? ($this->completedCount / ($this->completedCount + $this->failedCount))
            : 1.0;
    }

    public function providerFailureRate(string $provider): float
    {
        $failures = $this->providerFailures[$provider] ?? 0;
        $total    = $this->completedCount + $this->failedCount;
        return $total > 0 ? $failures / $total : 0.0;
    }

    public function avgNodeMs(): float
    {
        return $this->completedCount > 0
            ? $this->totalElapsedMs / $this->completedCount
            : 0.0;
    }

    // ── Recording helpers ─────────────────────────────────────────────────────

    public function recordCheckpoint(int $sizeBytes): void
    {
        $this->checkpointCount++;
        // Running average of checkpoint size
        $this->checkpointSizeBytes = (int) (
            ($this->checkpointSizeBytes * ($this->checkpointCount - 1) + $sizeBytes)
            / $this->checkpointCount
        );
    }

    public function recordNodeCompleted(string $nodeId, float $elapsedMs): void
    {
        $this->completedCount++;
        $this->nodeTiming[$nodeId] = $elapsedMs;
    }

    public function recordNodeFailed(string $nodeId, ?string $provider = null): void
    {
        $this->failedCount++;
        if ($provider !== null) {
            $this->providerFailures[$provider] = ($this->providerFailures[$provider] ?? 0) + 1;
        }
    }

    public function recordNodeSkipped(): void
    {
        $this->skippedCount++;
    }

    public function recordRetry(float $delayMs): void
    {
        $this->retryCount++;
        // Running average
        $this->meanRetryDelayMs = $this->retryCount > 1
            ? ($this->meanRetryDelayMs * ($this->retryCount - 1) + $delayMs) / $this->retryCount
            : $delayMs;
    }

    /** Heuristic critical path: longest single-node execution time. */
    public function computeCriticalPath(): void
    {
        if (!empty($this->nodeTiming)) {
            $this->criticalPathMs = max($this->nodeTiming);
        }
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'retryCount'          => $this->retryCount,
            'rollbackCount'       => $this->rollbackCount,
            'meanRetryDelayMs'    => $this->meanRetryDelayMs,
            'checkpointCount'     => $this->checkpointCount,
            'checkpointSizeBytes' => $this->checkpointSizeBytes,
            'completedCount'      => $this->completedCount,
            'failedCount'         => $this->failedCount,
            'skippedCount'        => $this->skippedCount,
            'totalElapsedMs'      => $this->totalElapsedMs,
            'criticalPathMs'      => $this->criticalPathMs,
            'idleTimeMs'          => $this->idleTimeMs,
            'successRate'         => $this->successRate(),
            'avgNodeMs'           => $this->avgNodeMs(),
            'providerFailures'    => $this->providerFailures,
        ];
    }
}
