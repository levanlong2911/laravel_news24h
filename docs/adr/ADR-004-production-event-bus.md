# ADR-004: Production Event Bus

**Status:** Proposed  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-001, ADR-002, ADR-003

---

## Context

The current pipeline is synchronous and monolithic:

```
StoryPlanner → SceneShotPlanner → ScenePlanner → GraphAssembler → Backend → done
```

Problems with this model at scale:

1. **No retry** — if Shot 12 of 30 fails at video generation, the entire pipeline restarts
2. **No resume** — a server crash loses all in-progress work
3. **No parallelism** — shots are compiled and rendered sequentially
4. **No progress visibility** — users cannot see which step is running
5. **No distribution** — everything runs on one worker; cannot scale horizontally
6. **Tight coupling** — adding a new step (voice, subtitle) requires modifying the pipeline class

The pipeline will grow significantly (Phases B through G). Each new phase adds
steps that can fail, retry, and run in parallel. The synchronous model cannot
support this without becoming a maintenance nightmare.

---

## Decision

Adopt an **event-driven pipeline** using Laravel's built-in event/queue system.
Each stage transition emits a domain event. Listeners react to events and enqueue
the next stage.

This does not require Kafka or RabbitMQ initially — Laravel Queue (Redis driver)
is sufficient for Phase B–D. The architecture is designed to be upgradeable.

---

## Event Taxonomy

### Lifecycle events (one per production)

| Event | Emitted when | Triggers |
|-------|-------------|---------|
| `ProductionCreated` | `VideoProject` created | BibleBuilder initialization |
| `BibleLocked` | `ProductionBible.lock()` called | ConstraintEngine validation |
| `ProductionCompleted` | All scenes published | Notification to user |
| `ProductionFailed` | Unrecoverable error | Alert + manual review queue |

### Scene-level events (one per scene)

| Event | Emitted when | Triggers |
|-------|-------------|---------|
| `ScenePlanned` | `SceneShotPlanner` completes one scene | ConstraintEngine for that scene |
| `ConstraintValidated` | `ConstraintEngine.validate()` passes | PlanningContextBuilder |
| `ConstraintFailed` | `ConstraintEngine` has ERROR-level violations | Alert + halt scene |
| `SceneRendered` | All shots in scene have `ShotRendered` | EditingOS.planScene() |
| `SceneFailed` | Max retries exceeded for any shot | Alert + manual review |

### Shot-level events (one per shot — the hot path)

| Event | Emitted when | Triggers |
|-------|-------------|---------|
| `ShotContextBuilt` | `PlanningContextBuilder` completes | ShotPlanner |
| `ShotCompiled` | `AfosPassManager.compileWithSnapshot()` succeeds | ImageGenerator or VideoRenderer |
| `ShotCompileFailed` | AFOS compilation error | Retry with adjusted ShotGoalIR |
| `ImageGenerated` | Image generation API returns | VideoRenderer |
| `VideoRendered` | Video generation API returns | VisualMemory.record() |
| `ShotFailed` | Max retries on video generation | Fallback or alert |

### Post-production events (one per production)

| Event | Emitted when | Triggers |
|-------|-------------|---------|
| `EditingFinished` | `EditingOS.plan()` produces EDL | VoiceSynthesizer |
| `VoiceSynthesized` | TTS completes | SubtitleGenerator |
| `SubtitleGenerated` | Subtitle file created | PublishJob |
| `PublishReady` | All assets assembled | Publisher |

---

## Event Contract

All production events implement a shared interface:

```php
namespace App\Events\FilmOS;

interface ProductionEvent
{
    public function productionId(): string;
    public function occurredAt(): \DateTimeImmutable;
    public function metadata(): array;   // for logging/tracing only
}
```

### Example events

```php
final class ShotCompiled implements ProductionEvent
{
    public function __construct(
        public readonly string           $productionId,
        public readonly string           $sceneId,
        public readonly string           $shotId,
        public readonly PromptIRSnapshot $snapshot,
        public readonly RenderContext    $renderContext,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}

    public function productionId(): string { return $this->productionId; }
    public function occurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function metadata(): array {
        return ['scene_id' => $this->sceneId, 'shot_id' => $this->shotId];
    }
}

final class ConstraintFailed implements ProductionEvent
{
    public function __construct(
        public readonly string           $productionId,
        public readonly string           $sceneId,
        public readonly ConstraintReport $report,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
    // ...
}

final class VideoRendered implements ProductionEvent
{
    public function __construct(
        public readonly string  $productionId,
        public readonly string  $sceneId,
        public readonly string  $shotId,
        public readonly string  $videoUrl,
        public readonly float   $durationMs,
        public readonly float   $costUsd,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
    // ...
}
```

---

## Event Flow Diagram

```
ProductionCreated
        │
        ▼
BibleLocked
        │
        ▼
ScenePlanned (scene 1)  ──── ScenePlanned (scene 2) ──── ScenePlanned (scene N)
        │                           │                           │
        ▼                           ▼                           ▼
ConstraintValidated           ConstraintValidated           ConstraintValidated
        │                                                       │
        ▼                                                       ▼
ShotContextBuilt (shot 1)                             ShotContextBuilt (shot 1)
        │
        ▼
ShotCompiled
        │
        ├── ImageGenerated (optional, image-first pipeline)
        │           │
        │           ▼
        └────────► VideoRendered
                        │
                        ▼
                   VisualMemory.record()  ←── (side effect, not event)
                        │
                        ▼
                [all shots done?]
                        │ yes
                        ▼
                   SceneRendered
                        │
                   [all scenes done?]
                        │ yes
                        ▼
                   EditingFinished
                        │
                        ▼
                   VoiceSynthesized
                        │
                        ▼
                   SubtitleGenerated
                        │
                        ▼
                   PublishReady
                        │
                        ▼
                   ProductionCompleted
```

---

## Retry Strategy

Each event handler defines its own retry policy:

```php
final class VideoRenderListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int   $tries   = 3;
    public int   $backoff = 60;   // seconds between retries

    public function handle(ShotCompiled $event): void
    {
        try {
            $videoUrl = $this->renderer->render(
                $event->snapshot->promptArtifacts(),
                $event->renderContext,
            );

            event(new VideoRendered(
                productionId: $event->productionId,
                sceneId:      $event->sceneId,
                shotId:       $event->shotId,
                videoUrl:     $videoUrl,
                durationMs:   ...,
                costUsd:      ...,
            ));

        } catch (RenderApiException $e) {
            if ($this->attempts() >= $this->tries) {
                event(new ShotFailed(...));
                $this->fail($e);
                return;
            }
            $this->release($this->backoff);
        }
    }
}
```

### Retry matrix

| Stage | Max retries | Backoff | On final failure |
|-------|------------|---------|-----------------|
| AFOS compilation | 2 | 5s | `ShotCompileFailed` → alert |
| Image generation | 3 | 30s | Fallback to next backend |
| Video generation | 3 | 60s | `ShotFailed` → manual review |
| Voice synthesis | 3 | 30s | Skip voice, flag for review |
| Subtitle | 2 | 10s | Skip subtitle, continue |
| Publish | 5 | 120s | `ProductionFailed` → alert |

---

## Parallelism Model

Shots within a scene that have no data dependencies can render in parallel.
`VisualMemory` provides the only ordering constraint:

```
Shot 1 (must run first — establishes AppearanceMemory)
        │
        ▼
Shot 2, Shot 3, Shot 4 (can run in parallel — read AppearanceMemory, don't write)
        │
        ▼
Shot 5 (requires Shot 4's state — SpatialMemory updated after Shot 4)
```

The `ShotContextBuilder` resolves this ordering and emits `ShotContextBuilt`
events in the correct sequence. Shots marked `parallel=true` can be dispatched
to separate queue workers simultaneously.

```php
final class ShotContextBuilt implements ProductionEvent
{
    public function __construct(
        // ...
        public readonly bool $parallel,      // can this shot run concurrently with others?
        public readonly ?string $dependsOn,  // shotId this shot must wait for, or null
    ) {}
}
```

---

## Queue Configuration

### Phase B (development/staging): Laravel Queue + Redis

```php
// config/queue.php
'connections' => [
    'filmos' => [
        'driver'     => 'redis',
        'connection' => 'default',
        'queue'      => 'filmos-production',
        'retry_after' => 300,
    ],
    'filmos-render' => [   // separate queue for expensive render jobs
        'driver'     => 'redis',
        'queue'      => 'filmos-render',
        'retry_after' => 600,
    ],
],
```

### Phase G (production scale): upgradeable to Horizon + separate workers

```bash
# Compilation workers (fast, many)
php artisan queue:work filmos --queue=filmos-production --sleep=1

# Render workers (slow, expensive — scale to demand)
php artisan queue:work filmos-render --queue=filmos-render --sleep=3 --timeout=300
```

### Future (Phase G+): RabbitMQ / Kafka

The event contracts (`ProductionEvent` interface) do not change.
Only the queue driver changes. All listeners remain unchanged.

---

## Event Store (observability)

All events are persisted to `production_events` table for:
- Pipeline resume after crash
- Debug replay
- Cost/latency analytics

```php
// Migration: production_events
Schema::create('production_events', function (Blueprint $table) {
    $table->id();
    $table->string('production_id')->index();
    $table->string('event_type');           // fully-qualified class name
    $table->string('scene_id')->nullable()->index();
    $table->string('shot_id')->nullable()->index();
    $table->json('payload');
    $table->string('status')->default('emitted');  // emitted | processed | failed
    $table->unsignedSmallInteger('attempt')->default(0);
    $table->timestamps();
});
```

### Resume from checkpoint

If a production crashes mid-render, it can resume from the last successfully
processed event:

```php
final class ProductionResumer
{
    public function resumeFrom(string $productionId): void
    {
        $lastSuccess = ProductionEventLog::where('production_id', $productionId)
            ->where('status', 'processed')
            ->orderByDesc('created_at')
            ->first();

        if ($lastSuccess === null) {
            // Start from beginning
            event(new ProductionCreated($productionId));
            return;
        }

        // Re-emit the next expected event based on last successful stage
        $this->replayFrom($lastSuccess);
    }
}
```

---

## Existing AFOS Event Bus

AFOS already has `PipelineEventBus` (in `AFOS/Passes/Events/`) for intra-compiler
events (`StageStarted`, `StageFinished`, `StageFailed`). This is **different** from
the production-level `ProductionEvent`:

| | AFOS PipelineEventBus | FilmOS ProductionEvent |
|---|---|---|
| Scope | Within one shot compilation | Entire production lifecycle |
| Persistence | No | Yes (production_events table) |
| Retry | No | Yes (queue-based) |
| Audience | Compiler internals | Pipeline orchestrator |
| Transport | In-process callback | Laravel Queue / Redis |

The two systems are independent and complementary. `ShotCompiled` (ProductionEvent)
is emitted after `AfosPassManager` finishes — it wraps the entire AFOS run.

---

## Directory Structure

```
app/
├── Events/
│   └── FilmOS/
│       ├── ProductionEvent.php          (interface)
│       ├── Lifecycle/
│       │   ├── ProductionCreated.php
│       │   ├── BibleLocked.php
│       │   ├── ProductionCompleted.php
│       │   └── ProductionFailed.php
│       ├── Scene/
│       │   ├── ScenePlanned.php
│       │   ├── ConstraintValidated.php
│       │   ├── ConstraintFailed.php
│       │   ├── SceneRendered.php
│       │   └── SceneFailed.php
│       ├── Shot/
│       │   ├── ShotContextBuilt.php
│       │   ├── ShotCompiled.php
│       │   ├── ShotCompileFailed.php
│       │   ├── ImageGenerated.php
│       │   ├── VideoRendered.php
│       │   └── ShotFailed.php
│       └── PostProduction/
│           ├── EditingFinished.php
│           ├── VoiceSynthesized.php
│           ├── SubtitleGenerated.php
│           └── PublishReady.php
│
└── Listeners/
    └── FilmOS/
        ├── OnProductionCreated.php
        ├── OnBibleLocked.php
        ├── OnScenePlanned.php
        ├── OnConstraintValidated.php
        ├── OnShotContextBuilt.php
        ├── OnShotCompiled.php
        ├── OnVideoRendered.php
        ├── OnSceneRendered.php
        ├── OnEditingFinished.php
        ├── OnVoiceSynthesized.php
        └── OnSubtitleGenerated.php
```

---

## Consequences

### Positive
- Any step can fail and retry without restarting the production
- A server crash loses at most the current in-flight job — resume from last event
- Shots can render in parallel on multiple workers
- Adding a new post-production step (e.g., color grading service) = new listener, no pipeline changes
- Full audit trail of every production in `production_events` table
- Progress visibility: query `production_events` to show user "Shot 12/30 rendering..."

### Negative
- Debugging is harder — trace spans multiple queue workers and events
- `production_events` table grows large for high-volume productions → needs TTL / archival
- Event ordering must be managed carefully (VisualMemory write-before-read constraint)
- Local development needs Redis running (acceptable)

### Not changing
- AFOS Compiler Core — `AfosPassManager` is still synchronous internally
- FilmOS model classes (ProductionBible, SceneGraph, etc.) — pure domain objects, no queue awareness
- All events are thin DTOs — they carry IDs and results, not domain logic

---

## References

- ADR-001: Freeze AFOS Compiler Core
- ADR-002: FilmOS Unified Model
- ADR-003: FilmOS Extended Engines
- ADR-005: Persistence Model (production_events table details)
- `app/Services/AI/AFOS/Passes/Events/PipelineEventBus.php` — intra-compiler events (different scope)
- `app/Services/AI/AFOS/Passes/AfosPassManager.php` — wrapped by `OnShotContextBuilt` listener
