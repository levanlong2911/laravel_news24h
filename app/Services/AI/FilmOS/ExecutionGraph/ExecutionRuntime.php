<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use App\Services\AI\FilmOS\EventBus\EventBus;
use App\Services\AI\FilmOS\EventBus\Events\CheckpointSavedEvent;
use App\Services\AI\FilmOS\EventBus\Events\ExecutionFinishedEvent;
use App\Services\AI\FilmOS\EventBus\Events\ExecutionStartedEvent;
use App\Services\AI\FilmOS\EventBus\Events\NodeCompletedEvent;
use App\Services\AI\FilmOS\EventBus\Events\NodeFailedEvent;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;

/**
 * Orchestrates task execution over an ExecutionGraph.
 *
 * HOW engine: topoSort → execute → checkpoint → resume.
 * Emits domain events at every lifecycle boundary (optional EventBus).
 * ADR-018: ExecutionGraph is recorder, ExecutionRuntime is runner.
 */
final class ExecutionRuntime
{
    public function __construct(
        private readonly CheckpointStore $checkpoints,
        private readonly ?EventBus       $eventBus = null,
    ) {}

    /**
     * Run the ExecutionGraph, resuming from checkpoint if one exists.
     *
     * @param  array<string, callable> $handlers  taskId → callable(): mixed
     */
    public function run(
        string         $executionId,
        ExecutionGraph $graph,
        array          $handlers,
    ): ExecutionResult {
        $startTime = hrtime(true) / 1e6;
        $resumed   = false;
        $metrics   = new ExecutionMetrics();

        // Check for existing checkpoint — resume if found
        $checkpoint = $this->checkpoints->load($executionId);
        if ($checkpoint !== null) {
            $graph   = $checkpoint;
            $resumed = true;
            $metrics->rollbackCount++;
        }

        $this->eventBus?->dispatch(new ExecutionStartedEvent(
            executionId:           $executionId,
            nodeCount:             $graph->nodeCount(),
            resumedFromCheckpoint: $resumed,
        ));

        $sorted   = GraphAlgorithms::topoSort($graph);
        $executed = [];
        $skipped  = [];

        foreach ($sorted as $node) {
            /** @var ExecutionNode $node */

            // Already COMPLETED từ checkpoint → skip
            if ($node->isCompleted()) {
                $skipped[] = $node->id;
                $metrics->recordNodeSkipped();
                continue;
            }

            // SKIPPED từ trước (dep đã failed) → giữ nguyên
            if ($node->isSkipped()) {
                $skipped[] = $node->id;
                $metrics->recordNodeSkipped();
                continue;
            }

            // Kiểm tra hard dependencies
            if (!$this->allHardDepsCompleted($graph, $node->id)) {
                $node->status = ExecutionNodeStatus::SKIPPED;
                $skipped[]    = $node->id;
                $metrics->recordNodeSkipped();
                $this->saveCheckpoint($executionId, $graph, $metrics);
                continue;
            }

            // Execute
            $wasFailedBefore  = $node->isFailed();
            $node->status     = ExecutionNodeStatus::RUNNING;
            $node->startedAt  = microtime(true);

            if ($wasFailedBefore) {
                // Resume: node được re-run sau failure
                $retryDelayMs = ($node->completedAt !== null)
                    ? (microtime(true) - $node->completedAt) * 1000
                    : 0.0;
                $node->retryCount++;
                $node->error = null;
                $metrics->recordRetry($retryDelayMs);
            }

            try {
                $handler = $handlers[$node->taskId]
                    ?? throw new \RuntimeException("No handler for task: {$node->taskId}");

                $node->result      = $handler();
                $node->status      = ExecutionNodeStatus::COMPLETED;
                $node->completedAt = microtime(true);

                $elapsedMs = $node->elapsedMs() ?? 0.0;
                $metrics->recordNodeCompleted($node->id, $elapsedMs);
                $this->eventBus?->dispatch(new NodeCompletedEvent($executionId, $node->id, $node->taskId, $elapsedMs));
            } catch (\Throwable $e) {
                $node->status      = ExecutionNodeStatus::FAILED;
                $node->error       = $e->getMessage();
                $node->completedAt = microtime(true);

                // Extract provider from error message if present (convention: "provider:name message")
                $provider = str_starts_with($e->getMessage(), 'provider:')
                    ? explode(' ', substr($e->getMessage(), 9), 2)[0]
                    : null;
                $metrics->recordNodeFailed($node->id, $provider);
                $this->eventBus?->dispatch(new NodeFailedEvent($executionId, $node->id, $node->taskId, $e->getMessage(), $node->retryCount, $provider));
            }

            $executed[] = $node->id;
            $this->saveCheckpoint($executionId, $graph, $metrics);
        }

        $totalMs                = (hrtime(true) / 1e6) - $startTime;
        $metrics->totalElapsedMs = $totalMs;
        $metrics->computeCriticalPath();

        // Clear checkpoint only on full success
        if ($graph->isFullyCompleted() && !$graph->hasFailures()) {
            $this->checkpoints->clear($executionId);
        }

        $this->eventBus?->dispatch(new ExecutionFinishedEvent(
            executionId:    $executionId,
            fullyCompleted: $graph->isFullyCompleted() && !$graph->hasFailures(),
            completedCount: $metrics->completedCount,
            failedCount:    $metrics->failedCount,
            skippedCount:   $metrics->skippedCount,
            totalElapsedMs: $totalMs,
        ));

        return new ExecutionResult(
            graph:                 $graph,
            totalElapsedMs:        $totalMs,
            executedNodeIds:       $executed,
            skippedNodeIds:        $skipped,
            resumedFromCheckpoint: $resumed,
            metrics:               $metrics,
        );
    }

    private function saveCheckpoint(string $executionId, ExecutionGraph $graph, ExecutionMetrics $metrics): void
    {
        $serialized = serialize($graph);
        $this->checkpoints->save($executionId, $graph);
        $sizeBytes = strlen($serialized);
        $metrics->recordCheckpoint($sizeBytes);
        $this->eventBus?->dispatch(new CheckpointSavedEvent($executionId, $sizeBytes, $metrics->completedCount));
    }

    private function allHardDepsCompleted(ExecutionGraph $graph, string $nodeId): bool
    {
        foreach ($graph->edges() as $edge) {
            if ($edge->toId !== $nodeId || !$edge->isDependency()) {
                continue;
            }
            $parent = $graph->node($edge->fromId);
            if ($parent === null || !$parent->isCompleted()) {
                return false;
            }
        }
        return true;
    }
}
