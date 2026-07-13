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
use Illuminate\Support\Str;

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

            // Hard dependency check → SKIPPED
            if (!$this->allHardDepsCompleted($graph, $node->id)) {
                $node->status          = ExecutionNodeStatus::SKIPPED;
                $states[$node->taskId] = $state->transitionTo(ExecutionNodeStatus::SKIPPED);
                $state                 = $states[$node->taskId];
                $skipped[]             = $node->id;
                $metrics->recordNodeSkipped();

                [$eventId, $parentId] = $this->nextEventIds($checkpointLog);
                $checkpointLog[] = new CheckpointEntry($node->taskId, 'skipped', ++$cpOrdinal, $eventId, $parentId);
                $this->saveCheckpoint($executionId, $graph, $metrics);
                continue;
            }

            // ── Transition to RUNNING ──────────────────────────────────────────
            $wasFailedBefore  = $node->isFailed();
            $now              = microtime(true);
            $node->status     = ExecutionNodeStatus::RUNNING;
            $node->startedAt  = $now;
            $runOverrides     = ['startedAt' => $now];

            if ($wasFailedBefore) {
                $retryDelayMs = ($node->completedAt !== null)
                    ? ($now - $node->completedAt) * 1000
                    : 0.0;
                $node->retryCount++;
                $node->error           = null;
                $runOverrides['error'] = null;   // explicit null to clear on retry
                $metrics->recordRetry($retryDelayMs);
            }

            $states[$node->taskId] = $state->transitionTo(ExecutionNodeStatus::RUNNING, $runOverrides);
            $state                 = $states[$node->taskId];

            try {
                $handler = $handlers[$node->taskId]
                    ?? throw new \RuntimeException("No handler for task: {$node->taskId}");

                $output = $handler();

                // ── COMPLETED ──────────────────────────────────────────────────
                $node->result      = $output;
                $node->status      = ExecutionNodeStatus::COMPLETED;
                $node->completedAt = microtime(true);

                $states[$node->taskId] = $state
                    ->transitionTo(ExecutionNodeStatus::COMPLETED, [
                        'result'      => $output,
                        'completedAt' => $node->completedAt,
                    ])
                    ->withAttempt(ExecutionNodeStatus::COMPLETED);
                $state = $states[$node->taskId];

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

                $states[$node->taskId] = $state
                    ->transitionTo(ExecutionNodeStatus::FAILED, [
                        'error'       => $e->getMessage(),
                        'completedAt' => $node->completedAt,
                    ])
                    ->withAttempt(ExecutionNodeStatus::FAILED);
                $state = $states[$node->taskId];

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

            [$eventId, $parentId] = $this->nextEventIds($checkpointLog);
            $checkpointLog[] = new CheckpointEntry($node->taskId, $state->status->value, ++$cpOrdinal, $eventId, $parentId);
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

    /**
     * Generate the next (eventId, parentEventId) pair for a checkpoint entry.
     * parentEventId is the eventId of the last entry already in the log.
     *
     * @param  CheckpointEntry[]  $checkpointLog
     * @return array{string, string|null}
     */
    private function nextEventIds(array $checkpointLog): array
    {
        $parentEventId = $checkpointLog !== []
            ? $checkpointLog[array_key_last($checkpointLog)]->eventId
            : null;

        return [Str::uuid()->toString(), $parentEventId];
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
