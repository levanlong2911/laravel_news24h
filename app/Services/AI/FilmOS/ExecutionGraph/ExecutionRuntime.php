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
 *
 * Dual tracking (Phase B):
 *   ExecutionNode        — mutable; keeps ExecutionGraph queries working.
 *   ExecutionRuntimeState — canonical mutable state for the snapshot layer.
 *   CheckpointEntry[]    — explicit checkpoint log; no timestamp derivation needed.
 *
 * ExecutionLayerBuilder reads ONLY from ExecutionRuntimeState + CheckpointEntry[].
 * It never reads ExecutionNode mutable fields directly.
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
        $startTime     = hrtime(true) / 1e6;
        $resumed       = false;
        $metrics       = new ExecutionMetrics();
        $cpOrdinal     = 0;

        /** @var array<string, ExecutionRuntimeState> $states */
        $states        = [];
        /** @var CheckpointEntry[] $checkpointLog */
        $checkpointLog = [];

        // ── Resume from checkpoint if available ────────────────────────────────
        $checkpoint = $this->checkpoints->load($executionId);
        if ($checkpoint !== null) {
            $graph   = $checkpoint;
            $resumed = true;
            $metrics->rollbackCount++;
        }

        // ── Initialise ExecutionRuntimeState from current node status ──────────
        // For fresh runs: all PENDING. For resumed runs: approximated from node state.
        foreach ($graph->nodes() as $node) {
            $states[$node->taskId] = ExecutionRuntimeState::fromNode($node);
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
            $state = $states[$node->taskId];

            // Already COMPLETED from checkpoint → skip
            if ($node->isCompleted()) {
                $skipped[] = $node->id;
                $metrics->recordNodeSkipped();
                continue;
            }

            // SKIPPED from before (dep already failed) → keep
            if ($node->isSkipped()) {
                $skipped[] = $node->id;
                $metrics->recordNodeSkipped();
                continue;
            }

            // Hard dependency check
            if (!$this->allHardDepsCompleted($graph, $node->id)) {
                // ── SKIPPED ──
                $node->status  = ExecutionNodeStatus::SKIPPED;
                $state->status = ExecutionNodeStatus::SKIPPED;
                $skipped[]     = $node->id;
                $metrics->recordNodeSkipped();

                $checkpointLog[] = new CheckpointEntry($node->taskId, 'skipped', ++$cpOrdinal);
                $this->saveCheckpoint($executionId, $graph, $metrics);
                continue;
            }

            // ── Execute ────────────────────────────────────────────────────────
            $wasFailedBefore = $node->isFailed();
            $node->status    = ExecutionNodeStatus::RUNNING;
            $state->status   = ExecutionNodeStatus::RUNNING;

            $node->startedAt  = microtime(true);
            $state->startedAt = $node->startedAt;

            if ($wasFailedBefore) {
                $retryDelayMs = ($node->completedAt !== null)
                    ? (microtime(true) - $node->completedAt) * 1000
                    : 0.0;
                $node->retryCount++;
                $node->error  = null;
                $state->error = null;
                $metrics->recordRetry($retryDelayMs);
            }

            try {
                $handler = $handlers[$node->taskId]
                    ?? throw new \RuntimeException("No handler for task: {$node->taskId}");

                $output = $handler();

                // ── COMPLETED ──────────────────────────────────────────────────
                $node->result      = $output;
                $node->status      = ExecutionNodeStatus::COMPLETED;
                $node->completedAt = microtime(true);

                $state->result      = $output;
                $state->status      = ExecutionNodeStatus::COMPLETED;
                $state->completedAt = $node->completedAt;
                $state->recordAttempt(ExecutionNodeStatus::COMPLETED);

                $elapsedMs = $node->elapsedMs() ?? 0.0;
                $metrics->recordNodeCompleted($node->id, $elapsedMs);
                $this->eventBus?->dispatch(new NodeCompletedEvent(
                    $executionId, $node->id, $node->taskId, $elapsedMs,
                ));
            } catch (\Throwable $e) {
                // ── FAILED ────────────────────────────────────────────────────
                $node->status      = ExecutionNodeStatus::FAILED;
                $node->error       = $e->getMessage();
                $node->completedAt = microtime(true);

                $state->status      = ExecutionNodeStatus::FAILED;
                $state->error       = $e->getMessage();
                $state->completedAt = $node->completedAt;
                $state->recordAttempt(ExecutionNodeStatus::FAILED);

                $provider = str_starts_with($e->getMessage(), 'provider:')
                    ? explode(' ', substr($e->getMessage(), 9), 2)[0]
                    : null;
                $metrics->recordNodeFailed($node->id, $provider);
                $this->eventBus?->dispatch(new NodeFailedEvent(
                    $executionId, $node->id, $node->taskId,
                    $e->getMessage(), $node->retryCount, $provider,
                ));
            }

            $executed[] = $node->id;

            $checkpointLog[] = new CheckpointEntry($node->taskId, $state->status->value, ++$cpOrdinal);
            $this->saveCheckpoint($executionId, $graph, $metrics);
        }

        $totalMs                 = (hrtime(true) / 1e6) - $startTime;
        $metrics->totalElapsedMs = $totalMs;
        $metrics->computeCriticalPath();

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
            states:                $states,
            checkpointLog:         $checkpointLog,
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function saveCheckpoint(
        string         $executionId,
        ExecutionGraph $graph,
        ExecutionMetrics $metrics,
    ): void {
        $serialized = serialize($graph);
        $this->checkpoints->save($executionId, $graph);
        $sizeBytes = strlen($serialized);
        $metrics->recordCheckpoint($sizeBytes);
        $this->eventBus?->dispatch(new CheckpointSavedEvent(
            $executionId, $sizeBytes, $metrics->completedCount,
        ));
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
