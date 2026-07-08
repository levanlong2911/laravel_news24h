# ADR-018: FilmOS Execution Graph Runtime

**Status:** Accepted  
**Date:** 2026-07-08  
**Revision:** 1  
**Deciders:** Chief Architect + Project Lead  
**Type:** Runtime Architecture  
**Depends on:** ADR-016 (Architecture Lock), ADR-017 (Meta Reasoning)

---

## Context

ADR-016 defines `DAGRuntime` for tracing WHY a decision was made.

What it does not define: how tasks are **orchestrated, retried, resumed, and rolled back** when things go wrong in a multi-step production.

Currently, if a render fails midway through a 4-shot production:
- The entire production restarts from zero
- There is no checkpoint
- Retry is manual and ad-hoc
- Parallel task execution is not guaranteed

This becomes critical at scale:
- 20-shot documentary: one failed shot restarts 19 successful renders ($2.28 wasted)
- Transient Kling API timeout: retries without backoff hit rate limits
- Provider switch mid-production: no rollback to completed shots

### The distinction ADR-016 intentionally deferred

```
DecisionDAG = WHY (causal trace, explainability, audit)
ExecutionGraph = HOW (task orchestration, retry, rollback, resume, parallelism)
```

These are **two different graphs** with **different purposes**. Conflating them would violate Invariant 1 (graphs should represent one thing clearly) and Invariant 3 (execution is DAGRuntime — but DAGRuntime records decisions, not workflow state).

---

## Decision

### The ExecutionGraph Runtime

```
ExecutionGraph (HOW to run tasks)
  ├── Node: RENDER_shot1  [CRITICAL, retry=2, timeout=15s]
  ├── Node: RENDER_shot2  [CRITICAL, retry=2, timeout=15s]
  ├── Node: RENDER_shot3  [IMPORTANT, retry=1]
  ├── Node: REVIEW_shot1  [depends on RENDER_shot1]
  ├── Node: PUBLISH       [depends on all REVIEW nodes, rollback=delete_assets]
  └── Edges: PARALLEL, THEN, RETRY, FALLBACK, ROLLBACK
```

```
DecisionDAG (WHY this output was produced)
  ├── FACT → MEANING → PLAN → INTENT → RENDER → REVIEW → CONSENSUS
  └── Full causal trace, confidence scores, rationale
```

---

## Core Types

### ExecutionNodeStatus

```php
enum ExecutionNodeStatus: string
{
    case PENDING    = 'pending';
    case RUNNING    = 'running';
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';
    case RETRYING   = 'retrying';
    case SKIPPED    = 'skipped';
    case ROLLED_BACK = 'rolled_back';
}
```

### ExecutionEdgeType

```php
enum ExecutionEdgeType: string
{
    case THEN     = 'then';       // B starts after A completes
    case PARALLEL = 'parallel';   // A and B start simultaneously
    case RETRY    = 'retry';      // B is A's retry (same task, new attempt)
    case FALLBACK = 'fallback';   // B starts only if A fails
    case ROLLBACK = 'rollback';   // B runs when A or its subtree rolls back
}
```

### RetryPolicy

```php
final class RetryPolicy
{
    public function __construct(
        public readonly int   $maxAttempts,
        public readonly int   $initialBackoffMs,
        public readonly float $backoffMultiplier,    // exponential: attempt N waits initialBackoff * multiplier^N
        public readonly int   $maxBackoffMs,
    ) {}

    public function waitMs(int $attempt): int
    {
        $wait = (int) ($this->initialBackoffMs * ($this->backoffMultiplier ** $attempt));
        return min($wait, $this->maxBackoffMs);
    }

    public static function none(): self     { return new self(0, 0, 1.0, 0); }
    public static function standard(): self { return new self(2, 3000, 2.0, 30000); }
    public static function generous(): self { return new self(3, 5000, 2.0, 60000); }
}
```

### ExecutionNode

```php
final class ExecutionNode
{
    public ExecutionNodeStatus $status  = ExecutionNodeStatus::PENDING;
    public ?mixed              $output  = null;
    public int                 $attempt = 0;
    public ?string             $error   = null;

    public function __construct(
        public readonly string       $id,
        public readonly string       $taskId,          // maps to FilmTask.id
        public readonly RetryPolicy  $retryPolicy,
        public readonly int          $timeoutMs,
        public readonly ?Closure     $rollback = null, // cleanup on failure
    ) {}

    public function canRetry(): bool
    {
        return $this->attempt < $this->retryPolicy->maxAttempts;
    }
}
```

### ExecutionEdge

```php
final class ExecutionEdge
{
    public function __construct(
        public readonly string            $fromId,
        public readonly string            $toId,
        public readonly ExecutionEdgeType $type,
    ) {}
}
```

### ExecutionGraph

```php
final class ExecutionGraph
{
    /** @var array<string, ExecutionNode> */
    private array $nodes = [];

    /** @var ExecutionEdge[] */
    private array $edges = [];

    public function __construct(
        public readonly string $productionId,
        public readonly string $dagRuntimeId,  // links back to DecisionDAG
    ) {}

    public function addNode(ExecutionNode $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(ExecutionEdge $edge): void
    {
        $this->edges[] = $edge;
    }

    /** @return ExecutionNode[] nodes with all THEN/PARALLEL dependencies completed */
    public function ready(): array
    {
        $completed = array_filter(
            $this->nodes,
            fn(ExecutionNode $n) => $n->status === ExecutionNodeStatus::COMPLETED,
        );
        $completedIds = array_keys($completed);

        $ready = [];
        foreach ($this->nodes as $node) {
            if ($node->status !== ExecutionNodeStatus::PENDING) {
                continue;
            }

            $dependencies = $this->dependenciesOf($node->id);
            if (array_diff($dependencies, $completedIds) === []) {
                $ready[] = $node;
            }
        }
        return $ready;
    }

    private function dependenciesOf(string $nodeId): array
    {
        $deps = [];
        foreach ($this->edges as $edge) {
            if ($edge->toId === $nodeId
                && in_array($edge->type, [ExecutionEdgeType::THEN, ExecutionEdgeType::PARALLEL], true)
            ) {
                $deps[] = $edge->fromId;
            }
        }
        return $deps;
    }

    public function isComplete(): bool
    {
        foreach ($this->nodes as $node) {
            if (!in_array($node->status, [
                ExecutionNodeStatus::COMPLETED,
                ExecutionNodeStatus::SKIPPED,
                ExecutionNodeStatus::ROLLED_BACK,
            ], true)) {
                return false;
            }
        }
        return true;
    }

    public function checkpoint(): array
    {
        $state = [];
        foreach ($this->nodes as $id => $node) {
            $state[$id] = [
                'status'  => $node->status->value,
                'output'  => $node->output,
                'attempt' => $node->attempt,
                'error'   => $node->error,
            ];
        }
        return $state;
    }
}
```

### ExecutionRuntime

```php
/**
 * Orchestrates the ExecutionGraph: runs ready nodes, handles retry/fallback/rollback.
 * Separate from DAGRuntime (ADR-016) — this manages HOW, not WHY.
 *
 * Every node completion also records a DAGRuntime.execute() node,
 * preserving the causal trace (Invariant 3 is not violated because
 * DAGRuntime.execute() IS called — ExecutionRuntime delegates to it).
 */
final class ExecutionRuntime
{
    public function __construct(
        private readonly FilmKernel  $kernel,
        private readonly DAGRuntime  $dagRuntime,
        private readonly CheckpointStore $checkpoints,
    ) {}

    public function run(ExecutionGraph $graph): ExecutionResult
    {
        // Resume from checkpoint if available
        $this->restore($graph);

        while (!$graph->isComplete()) {
            $ready = $graph->ready();

            if (empty($ready)) {
                // All pending nodes are blocked — check for failures needing rollback
                $this->rollbackOnDeadlock($graph);
                break;
            }

            // Submit ready nodes to FilmKernel (runs them in priority order)
            foreach ($ready as $node) {
                $node->status  = ExecutionNodeStatus::RUNNING;
                $node->attempt++;

                $task   = $this->kernel->findTask($node->taskId);
                $result = $this->kernel->runTask($task);

                if ($result->success) {
                    $node->status = ExecutionNodeStatus::COMPLETED;
                    $node->output = $result->output;
                    $this->checkpoints->save($graph->productionId, $graph->checkpoint());
                } else {
                    if ($node->canRetry()) {
                        $node->status = ExecutionNodeStatus::RETRYING;
                        sleep((int) ($node->retryPolicy->waitMs($node->attempt) / 1000));
                        // Re-queue by resetting status to PENDING for next iteration
                        $node->status = ExecutionNodeStatus::PENDING;
                    } else {
                        $node->status = ExecutionNodeStatus::FAILED;
                        $node->error  = $result->error;
                        $this->triggerFallbackOrRollback($graph, $node);
                    }
                }
            }
        }

        return new ExecutionResult($graph);
    }

    private function triggerFallbackOrRollback(ExecutionGraph $graph, ExecutionNode $failed): void
    {
        // Check for FALLBACK edges
        foreach ($graph->edges() as $edge) {
            if ($edge->fromId === $failed->id && $edge->type === ExecutionEdgeType::FALLBACK) {
                $graph->node($edge->toId)->status = ExecutionNodeStatus::PENDING;
                return;
            }
        }

        // No fallback — trigger ROLLBACK on nodes that depended on this
        foreach ($graph->edges() as $edge) {
            if ($edge->fromId === $failed->id && $edge->type === ExecutionEdgeType::ROLLBACK) {
                $rollbackNode = $graph->node($edge->toId);
                if ($rollbackNode->rollback !== null) {
                    ($rollbackNode->rollback)();
                }
                $rollbackNode->status = ExecutionNodeStatus::ROLLED_BACK;
            }
        }
    }

    private function restore(ExecutionGraph $graph): void
    {
        $saved = $this->checkpoints->load($graph->productionId);
        if (empty($saved)) {
            return;
        }

        foreach ($saved as $nodeId => $state) {
            $node = $graph->node($nodeId);
            if ($node === null) {
                continue;
            }
            $node->status  = ExecutionNodeStatus::from($state['status']);
            $node->output  = $state['output'];
            $node->attempt = $state['attempt'];
            $node->error   = $state['error'];
        }
    }

    private function rollbackOnDeadlock(ExecutionGraph $graph): void
    {
        foreach ($graph->nodes() as $node) {
            if ($node->status === ExecutionNodeStatus::FAILED && $node->rollback !== null) {
                ($node->rollback)();
                $node->status = ExecutionNodeStatus::ROLLED_BACK;
            }
        }
    }
}
```

### Supporting types

```php
final class ExecutionResult
{
    public function __construct(private readonly ExecutionGraph $graph) {}

    public function isSuccess(): bool
    {
        foreach ($this->graph->nodes() as $node) {
            if ($node->status === ExecutionNodeStatus::FAILED) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> outputs keyed by node ID */
    public function outputs(): array
    {
        $out = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->status === ExecutionNodeStatus::COMPLETED) {
                $out[$node->id] = $node->output;
            }
        }
        return $out;
    }
}

interface CheckpointStore
{
    public function save(string $productionId, array $state): void;
    public function load(string $productionId): array;
    public function clear(string $productionId): void;
}

final class CacheCheckpointStore implements CheckpointStore
{
    private const TTL_HOURS = 24;

    public function save(string $productionId, array $state): void
    {
        cache()->put("filmos_checkpoint_{$productionId}", $state, now()->addHours(self::TTL_HOURS));
    }

    public function load(string $productionId): array
    {
        return cache()->get("filmos_checkpoint_{$productionId}", []);
    }

    public function clear(string $productionId): void
    {
        cache()->forget("filmos_checkpoint_{$productionId}");
    }
}
```

---

## ExecutionGraph vs DecisionDAG — the clear separation

| | DecisionDAG | ExecutionGraph |
|---|---|---|
| **Purpose** | WHY: causal trace and explainability | HOW: task orchestration and reliability |
| **Nodes** | FACT, MEANING, PLAN, INTENT, RENDER, REVIEW | Task execution units with retry/timeout/rollback |
| **Edges** | Causal edges (caused, influenced) | Dependency edges (THEN, PARALLEL, RETRY, FALLBACK, ROLLBACK) |
| **Written by** | `DAGRuntime.execute()` — one node per meaningful output | `ExecutionRuntime` — one node per task attempt |
| **Read by** | `filmos:explain-shot`, `filmos:check-invariants`, auditors | `ExecutionRuntime` for scheduling; operators for monitoring |
| **Invariant** | Invariant 3 (no dual-write — DAGRuntime IS the record) | Does not touch Invariant 3 (delegates to DAGRuntime for WHY) |
| **Retention** | Long-term (audit log) | Short-term (until production completes + 24h) |

---

## Phase 1 implementation scope

| Component | Phase 1 |
|---|---|
| `ExecutionNode`, `ExecutionEdge`, `ExecutionGraph` | Real |
| `ExecutionNodeStatus`, `ExecutionEdgeType` (enums) | Real |
| `RetryPolicy` | Real |
| `ExecutionRuntime.run()` | Real — sequential for Phase 1, parallel in Phase 2 |
| `ExecutionRuntime` retry loop | Real |
| `ExecutionRuntime` rollback | Real |
| `CacheCheckpointStore` | Real |
| `ExecutionResult` | Real |
| True parallel execution (Laravel queues / fibers) | **Phase 2** |

---

## Files to create

```
app/Services/AI/FilmOS/Execution/
├── ExecutionNode.php
├── ExecutionEdge.php
├── ExecutionGraph.php
├── ExecutionNodeStatus.php    (enum)
├── ExecutionEdgeType.php      (enum)
├── RetryPolicy.php
├── ExecutionResult.php
├── ExecutionRuntime.php
├── CheckpointStore.php        (interface)
└── CacheCheckpointStore.php
```

---

## Consequences

**Gains:**
- Retry without restarting the whole production
- Resume from last successful shot after a crash
- Rollback cleans up storage when a production partially fails
- Future: swap ExecutionRuntime for a real workflow engine (Temporal) without changing domain code
- Operators can see exactly which task failed and at which attempt

**Risks:**
- CheckpointStore adds serialization complexity — outputs must be serializable
- Retry with backoff increases total latency — must be within `PlanObjectives.maxLatencyMs` extended budget
- Rollback must be idempotent — deleting an already-deleted asset must not throw

**Invariant compliance:**
- Invariant 3 not violated: `ExecutionRuntime` delegates to `DAGRuntime.execute()` for WHY recording
- Invariant 5 not violated: trace-back still works via DAGRuntime, not ExecutionGraph
- Invariant 2 compliant: ExecutionGraph determines execution order (task dependency), not layer numbers

---

## ADR Chain closure

| ADR | Answers |
|---|---|
| ADR-012 | What does the system consist of? |
| ADR-013 | How does it understand the world? |
| ADR-014 | How does it organize knowledge? |
| ADR-015 | How does it execute? |
| ADR-016 | How is the architecture governed? |
| ADR-017 | How does the system choose its own strategy? |
| **ADR-018** | **How does the system run tasks reliably?** |

**With ADR-018, the architecture is fully closed. No further foundational ADRs before Phase 2 sign-off.**

Future ADRs (ADR-019+) address implementation-specific decisions or post-Phase-2 concepts (EntityGraph, EventGraph, Simulation).

---

## References

- ADR-016 Invariant 3: `DAGRuntime.execute()` is the single source of truth for WHY — `ExecutionRuntime` delegates to it
- ADR-017: `RetryBudget` from `SystemStrategy` feeds into `RetryPolicy` for `ExecutionNode`
- ADR-015: `DAGRuntime` records decisions — `ExecutionRuntime` orchestrates tasks
