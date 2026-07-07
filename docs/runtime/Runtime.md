# Production Runtime Design

**Date:** 2026-07-07  
**Type:** Runtime design — NOT an ADR, NOT domain model  
**Scope:** How the system runs at execution time (Phase G onwards, referenced by Phase A.5 for job patterns)

> ADRs answer "what we decided."  
> This document answers "how it actually runs."

---

## The Runtime Stack

```
┌─────────────────────────────────────────────────────────────────┐
│  PRODUCER AI                                                    │
│  Receives: topic / article / brief                              │
│  Outputs:  ProductionJob dispatched to Scheduler               │
│                                                                 │
│  Steps: Research → Script → Story → Budget check → Queue       │
└─────────────────────────┬───────────────────────────────────────┘
                          │  ProductionJob
┌─────────────────────────▼───────────────────────────────────────┐
│  SCHEDULER                                                      │
│  Receives: ProductionJob                                        │
│  Outputs:  Ordered list of StageTickets                         │
│                                                                 │
│  Responsibilities:                                              │
│  - Determine stage execution order (topological sort of deps)  │
│  - Estimate cost and duration per stage                         │
│  - Apply budget constraints (BudgetEngine in Phase G)          │
│  - Mark stages: SEQUENTIAL | PARALLEL | DEFERRED               │
└─────────────────────────┬───────────────────────────────────────┘
                          │  StageTickets[]
┌─────────────────────────▼───────────────────────────────────────┐
│  JOB DISPATCHER                                                 │
│  Receives: StageTickets[]                                       │
│  Outputs:  Laravel Jobs dispatched to queues                   │
│                                                                 │
│  Queues:                                                        │
│  - planning (fast, CPU-only, no external API)                  │
│  - rendering (slow, calls Kling/Veo/Runway API)                │
│  - audio     (TTS, music generation)                           │
│  - editing   (video assembly, subtitle burn)                   │
│  - publish   (platform upload, CDN flush)                      │
└─────────────────────────┬───────────────────────────────────────┘
                          │  Jobs run on Workers
┌─────────────────────────▼───────────────────────────────────────┐
│  CHECKPOINT STORE                                               │
│  Every state transition persists a checkpoint.                  │
│  On crash: reload latest checkpoint, re-enter at that phase.   │
│                                                                 │
│  Table: production_checkpoints                                  │
│    id, production_id, phase, entity_id, state_json, created_at │
└─────────────────────────┬───────────────────────────────────────┘
                          │  Completed jobs fire Events
┌─────────────────────────▼───────────────────────────────────────┐
│  EVENT BUS  (ADR-004)                                           │
│  Events: ShotRendered, ScenePlanned, BibleLocked, VideoReady   │
│  Listeners: update DB, trigger next job, notify UI             │
└─────────────────────────┬───────────────────────────────────────┘
                          │
┌─────────────────────────▼───────────────────────────────────────┐
│  PUBLISH GATEWAY                                                │
│  Receives: all shot videos + audio + subtitles assembled       │
│  Steps:    QA check → stitch → upload → CDN flush → done       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Production lifecycle states

```
DRAFT        Article received, no processing started
     │
RESEARCHING  ProducerAI running (search, summarize, outline)
     │
SCRIPTED     Script + Story produced, awaiting approval (optional gate)
     │
PLANNING     FilmOS building ProductionBible + SceneGraph + ShotPlan
     │
RENDERING    Shots dispatched to Kling/Veo/Runway; polling in progress
     │
ASSEMBLING   All shots done; audio/subtitle/editing jobs running
     │
QA           Automated quality gate (resolution, duration, content check)
     │
PUBLISHED    Uploaded to platform(s); CDN flushed; done
     │
  FAILED     (any stage) — retryable according to retry policy below
```

---

## Parallelism model

| Stage | Execution mode | Reason |
|-------|---------------|--------|
| Research | Sequential | LLM context must accumulate |
| Script | Sequential | Each scene depends on prior scenes |
| Bible/Planning | Sequential | Character state is cumulative |
| Shot compilation (AFOS) | Parallel (per shot) | Shots are independent after planning |
| Rendering (Kling API) | Parallel (per shot) | Each shot is an independent API call |
| Audio (TTS per line) | Parallel | Lines are independent |
| Music | Sequential | Needs total duration first |
| Editing/Assembly | Sequential | Needs all shots done |
| QA | Sequential | Needs assembled output |
| Publish | Atomic | All-or-nothing per platform |

---

## Checkpoint model

Every state transition writes a checkpoint. Checkpoint granularity:

```
production_checkpoints:
  id              UUID
  production_id   UUID          ← links to this production
  phase           VARCHAR(50)   ← PLANNING | RENDERING | etc.
  entity_id       VARCHAR(255)  ← shotId | sceneId | null
  state_json      JSON          ← serialized domain state at this point
  worker_id       VARCHAR(100)  ← which worker wrote this
  created_at      TIMESTAMP
```

**Resume logic:**
1. On worker start: `SELECT * FROM production_checkpoints WHERE production_id=? ORDER BY created_at DESC LIMIT 1`
2. Load `state_json` → deserialize domain state
3. Re-enter at `phase` (not from beginning)
4. Skip already-completed entities (check `entity_id` against completed list)

**Checkpoint written at:**
- After every AFOS compilation (1 per shot)
- After every Kling task submitted (1 per shot)
- After every Kling poll → COMPLETED (1 per shot)
- After every scene assembled
- After QA gate pass

---

## Retry policy

| Phase | Max attempts | Backoff strategy |
|-------|-------------|------------------|
| Research / Script | 3 | 5s → 30s → 5min |
| AFOS compilation | 1 | No retry (deterministic — same input = same failure) |
| Kling API submit | 5 | 30s → 2min → 10min → 30min → 2h |
| Kling API poll | 20 | 15s fixed (max 5min total) |
| Audio / TTS | 3 | 30s → 2min → 10min |
| Editing | 2 | 1min → 10min |
| Publish | 3 | 1min → 5min → 30min |

**Dead letter queue:** After max retries, the job goes to `failed_jobs` (Laravel default). A separate `FailureReporter` fires `ProductionFailed` event → notify operator.

**Idempotency:** All jobs must be safe to re-run. If a Kling task was already submitted, check `kling_task_id` before re-submitting.

---

## Cancellation saga

When `ProductionCancelled` event fires:

1. `CancellationSaga::handle()` loads all active jobs for `production_id`
2. Sets their status to `CANCELLED` in DB (they check on next run and short-circuit)
3. For RENDERING jobs: calls `KlingApiClient::cancel($taskId)` if Kling supports it
4. Releases quota reservation (BudgetEngine in Phase G)
5. Archives checkpoint state (`DELETE FROM production_checkpoints WHERE production_id=?` — keep for 7 days)
6. Fires `ProductionArchived` event

---

## Queue configuration (Laravel Horizon or similar)

```php
// config/horizon.php (example)
'environments' => [
    'production' => [
        'supervisor-planning'   => ['queue' => ['planning'],   'processes' => 4,  'timeout' => 60],
        'supervisor-rendering'  => ['queue' => ['rendering'],  'processes' => 10, 'timeout' => 600],
        'supervisor-audio'      => ['queue' => ['audio'],      'processes' => 4,  'timeout' => 120],
        'supervisor-editing'    => ['queue' => ['editing'],    'processes' => 2,  'timeout' => 300],
        'supervisor-publish'    => ['queue' => ['publish'],    'processes' => 2,  'timeout' => 120],
    ],
]
```

Rendering gets 10 processes because Kling calls are I/O bound (HTTP polling).  
Editing gets 2 processes because FFmpeg is CPU bound.

---

## Phase A.5 implementation (simplified)

Phase A.5 does not implement the full runtime above. It uses:

```
Controller → RenderShotVideoJob (planning queue)
                  ↓ dispatches
             PollKlingVideoJob  (rendering queue)
                  ↓ on COMPLETED
             stores video_url in pipeline_runs
```

The full Scheduler, Checkpoint, Saga pattern comes in Phase G.  
Phase A.5 is the minimal viable path to first video.

---

## Phase G additions (not in Phase A.5)

- `Scheduler` class — topological sort, budget check
- `JobDispatcher` class — replaces manual job dispatch in Controller
- `CheckpointStore` — proper table + resume logic
- `CancellationSaga` — graceful cancellation
- `FailureReporter` — dead letter queue monitoring
- `ProducerAI` — Research → Script → Brief → dispatch
- `BudgetEngine` — cost estimation and cap enforcement
- `PluginRegistry` — swap Kling → Veo → Runway without code changes
