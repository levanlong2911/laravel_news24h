# Implementation Playbook

**Date:** 2026-07-07  
**Status:** ACTIVE — updated at each phase completion  
**Rule:** Open this file every morning. Mark tasks done as you finish them. Never plan ahead of Phase A.5.

---

## How to use this document

- Each phase has a **Done Definition** — the system must pass it before moving to the next phase
- Each task lists **exact files to create**, not vague descriptions
- Build in **vertical slices**: after each phase, run a real Article → Video pipeline end-to-end
- When a task reveals a design issue, amend the relevant ADR (Level 2) or open an issue (Level 1)

---

## Phase A.5 — First Real Video [IMMEDIATE PRIORITY]

> **Goal:** Run the existing pipeline end-to-end and produce one real video from Kling.

**Why A.5 and not B?** `GraphAssembler` already calls `AfosPassManager::compile()` and gets a prompt string. The full chain exists. Only the Kling HTTP call is missing.

**Vertical slice:**
```
Article input (manual)
↓ StoryPlanner::plan()          [exists]
↓ SceneShotPlanner::plan()      [exists]
↓ ScenePlanner pipeline         [exists]
↓ GraphAssembler::assemble()    [exists]
↓ ShotGoalIRAdapter::to*()      [exists]
↓ AfosPassManager::compile()    [exists — 517 tests]
↓ KlingBackend::serialize()     [exists — returns prompt string]
↓ KlingApiClient::submit()      [MISSING ← build this]
↓ Video URL stored              [MISSING ← build this]
```

### Task A.5.1 — Kling API client

**Files to create:**
```
app/Services/AI/Providers/Kling/KlingApiClient.php
app/Services/AI/Providers/Kling/KlingVideoRequest.php
app/Services/AI/Providers/Kling/KlingVideoResponse.php
app/Services/AI/Providers/Kling/KlingApiException.php
config/kling.php
```

**KlingApiClient interface:**
```php
final class KlingApiClient
{
    public function submitVideo(KlingVideoRequest $request): string;   // returns taskId
    public function pollStatus(string $taskId): KlingVideoResponse;    // PENDING|PROCESSING|COMPLETED|FAILED
    public function waitForCompletion(string $taskId, int $timeoutSec = 300): KlingVideoResponse;
}
```

**KlingVideoRequest fields:** `prompt`, `negativePrompt`, `durationSec`, `aspectRatio`, `mode` (std/pro)

**KlingVideoResponse fields:** `taskId`, `status`, `videoUrl`, `thumbnailUrl`, `errorMessage`

**Tests to write:**
```
tests/Unit/Providers/Kling/KlingApiClientTest.php    ← mock HTTP, test request shape + polling
tests/Unit/Providers/Kling/KlingVideoRequestTest.php ← validation (duration range, aspect ratio)
```

**Done:** `KlingApiClient::submitVideo()` returns a taskId; `waitForCompletion()` returns a video URL.

---

### Task A.5.2 — Video render job

**Files to create:**
```
app/Jobs/RenderShotVideoJob.php
app/Jobs/PollKlingVideoJob.php
```

**RenderShotVideoJob:**
1. Accepts `shotId`, `prompt`, `negativePrompt`, `durationSec`
2. Calls `KlingApiClient::submitVideo()`
3. Stores `taskId` in `pipeline_runs` (or new `render_jobs` table)
4. Dispatches `PollKlingVideoJob::dispatch($taskId)->delay(30 seconds)`

**PollKlingVideoJob:**
1. Calls `KlingApiClient::pollStatus($taskId)`
2. If COMPLETED: store video URL, fire `VideoRendered` event
3. If FAILED: store error, increment retry count, fire `RenderFailed` event
4. If PENDING/PROCESSING: re-dispatch itself with 15-second delay (max 20 retries)

**Migration (if needed):**
```
database/migrations/xxxx_add_kling_fields_to_pipeline_runs.php
Adds: task_id, video_url, thumbnail_url, render_status, render_error
```

**Done:** Submit a job, wait 2 minutes, see a video URL in the database.

---

### Task A.5.3 — Wire into existing controller

Find the controller that currently calls `GraphAssembler::assemble()` and add the job dispatch:

```php
// After GraphAssembler returns the shot graph:
foreach ($shotGraph as $shot) {
    RenderShotVideoJob::dispatch(
        shotId:        $shot['id'],
        prompt:        $shot['prompt'],
        negativePrompt: $shot['negativePrompt'] ?? '',
        durationSec:   $shot['duration'],
    );
}
```

**Done Definition for Phase A.5:**
- [ ] Submit one article via the existing UI
- [ ] `pipeline_runs` table shows a task_id from Kling
- [ ] After ~2 minutes, `video_url` is populated
- [ ] Video is watchable in browser

---

## Phase B — FilmOS Core

> **Goal:** Multi-shot productions with consistent characters and a locked style bible.

**Depends on:** Phase A.5 done. ADR-002.

### Task B1 — ProductionBible

**Files to create:**
```
app/Services/AI/FilmOS/Core/ProductionBible.php
app/Services/AI/FilmOS/Core/FrozenProductionBible.php
app/Services/AI/FilmOS/Core/StyleBible.php
app/Services/AI/FilmOS/Repositories/ProductionBibleRepository.php
app/Repositories/Eloquent/EloquentProductionBibleRepository.php
tests/Unit/FilmOS/Core/ProductionBibleTest.php
database/migrations/xxxx_create_production_bibles_table.php
```

**Schema:** `production_bibles` table:
`id, production_id, version, locked_at, style_json, world_json, character_json, created_at`

**ProductionBible API:**
```php
$bible = ProductionBible::draft($productionId);
$bible = $bible->withStyle($styleBible);
$bible = $bible->withWorld($worldModule);
$frozen = $bible->lock();          // returns FrozenProductionBible
$frozen->shotCanAccess();          // true — FrozenProductionBible is passed to GraphAssembler
```

**Tests:** immutable after lock, version increments, serialize/deserialize round-trip, lock() prevents further mutations.

**Done:** `ProductionBible::lock()` returns a `FrozenProductionBible` that persists to DB and can be reloaded.

---

### Task B2 — WorldModule

**Files to create:**
```
app/Services/AI/FilmOS/Core/WorldModule.php
app/Services/AI/FilmOS/Core/WorldModel.php
app/Services/AI/FilmOS/Core/Location.php
app/Services/AI/FilmOS/Core/LocationState.php
tests/Unit/FilmOS/Core/WorldModuleTest.php
```

**WorldModule API:**
```php
$world = WorldModule::empty();
$world = $world->addLocation(Location::define('lobby', 'Grand lobby, marble floors, golden light'));
$state = $world->stateAt('lobby');           // LocationState (lighting, time of day, objects present)
$world = $world->applyTransition('lobby', new LightingTransition('golden' → 'dark'));
```

**Done:** WorldModule tracks location state across shots. `stateAt()` returns deterministic state.

---

### Task B3 — CharacterModule

**Files to create:**
```
app/Services/AI/FilmOS/Core/CharacterModule.php
app/Services/AI/FilmOS/Core/CharacterDefinition.php
app/Services/AI/FilmOS/Core/CharacterState.php
app/Services/AI/FilmOS/Core/AppearanceAnchor.php
tests/Unit/FilmOS/Core/CharacterModuleTest.php
```

**CharacterDefinition fields:** `characterId`, `name`, `appearanceDescription`, `defaultEmotion`, `costumeByScene`

**CharacterState fields:** `characterId`, `shotId`, `emotion`, `location`, `posture`, `isPresent`

**CharacterModule API:**
```php
$module = CharacterModule::empty();
$module = $module->define(CharacterDefinition::create('hero', 'Tall man, dark suit, silver watch'));
$state  = $module->stateAt('hero', shotId: 'shot_003');   // CharacterState
$module = $module->recordTransition('hero', shotId: 'shot_004', emotion: Emotion::CALM);
```

**Done:** `CharacterState` for any character at any shotId is consistent across the production.

---

### Task B4 — AssetModule

**Files to create:**
```
app/Services/AI/FilmOS/Core/AssetModule.php
app/Services/AI/FilmOS/Core/AssetDefinition.php
app/Services/AI/FilmOS/Core/AssetInstance.php
tests/Unit/FilmOS/Core/AssetModuleTest.php
```

**Done:** Assets (car, kettle, door) can be registered with a description and queried per shot.

---

### Task B5 — ConstraintEngine (8 constraints)

**Files to create:**
```
app/Services/AI/FilmOS/Engines/ConstraintEngine.php
app/Services/AI/FilmOS/Engines/Constraints/PhysicsConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/ContinuityConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/SemanticConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/CameraConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/LightingConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/EmotionConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/PresenceConstraint.php
app/Services/AI/FilmOS/Engines/Constraints/TemporalConstraint.php
app/Services/AI/FilmOS/Engines/ConstraintReport.php
tests/Unit/FilmOS/Engines/ConstraintEngineTest.php
```

**ConstraintEngine API:**
```php
$engine = ConstraintEngine::withDefaults();
$report = $engine->evaluate($planningContext);   // ConstraintReport
if ($report->hasBlockers()) {
    throw new ConstraintViolationException($report->blockers());
}
```

**Done:** All 8 constraints evaluate without throwing. At least 3 have unit tests with failing cases.

---

### Task B6 — SceneGraph v2 (ShotNode)

Replace existing `SceneGraph/ShotSceneGraph.php` with typed `ShotNode`:

```
app/Services/AI/FilmOS/Core/SceneGraph/SceneNode.php
app/Services/AI/FilmOS/Core/SceneGraph/ShotNode.php
app/Services/AI/FilmOS/Core/SceneGraph/SceneGraphV2.php
tests/Unit/FilmOS/Core/SceneGraph/SceneGraphV2Test.php
```

**Done:** SceneGraphV2 can represent a multi-scene production with N shots per scene.

---

### Task B7 — PlanningContext (decomposed)

**Files to create:**
```
app/Services/AI/FilmOS/Core/Planning/ShotContext.php
app/Services/AI/FilmOS/Core/Planning/VisualContext.php
app/Services/AI/FilmOS/Core/Planning/CharacterContext.php
app/Services/AI/FilmOS/Core/Planning/MotionContext.php
app/Services/AI/FilmOS/Core/Planning/EditingContext.php
app/Services/AI/FilmOS/Core/Planning/PlanningContext.php       ← aggregate
app/Services/AI/FilmOS/Core/Planning/PlanningContextBuilder.php
tests/Unit/FilmOS/Core/Planning/PlanningContextTest.php
```

**Done:** `PlanningContextBuilder` accepts a `FrozenProductionBible` and a `ShotNode` and produces a fully populated `PlanningContext`.

---

### Task B8 — Wire into GraphAssembler

Update `GraphAssembler::assemble()` to:
1. Accept a `FrozenProductionBible` as a parameter
2. Build a `PlanningContext` per shot using `PlanningContextBuilder`
3. Pass `PlanningContext` to `ShotGoalIRAdapter` (or replace the adapter with native construction)
4. Fire `ScenePlanned` event after each scene

**Done Definition for Phase B:**
- [ ] Run end-to-end with a 3-scene production (9+ shots)
- [ ] Character X wears the same costume in shot 1 and shot 7
- [ ] Location lighting is consistent across shots in the same scene
- [ ] ConstraintEngine blocks a physically impossible shot before Kling API is called
- [ ] `FrozenProductionBible` is stored and reloadable from DB

---

## Production Runtime Specification

> Referenced from ADR-004. Describes the runtime orchestration layer.  
> Not an ADR — describes implementation, not decisions.

### Lifecycle states

```
DRAFT → QUEUED → PLANNING → RENDERING → QA → PUBLISHED
                                  ↓
                              FAILED (retryable)
```

### Scheduler rules

1. Planning is sequential (shots depend on prior shot state)
2. Rendering is parallel (shots in different scenes can render simultaneously)
3. QA is sequential (requires all shots rendered)
4. Publish is atomic (all-or-nothing)

### Checkpoint model

Every state transition persists a checkpoint:
```
production_checkpoints: id, production_id, phase, shot_id, state_json, created_at
```

On resume after crash: load latest checkpoint, re-enter at that phase.

### Cancellation

`ProductionCancelled` event triggers a saga that:
1. Marks all `RENDERING` jobs as cancelled
2. Releases API quota
3. Archives checkpoint state

### Retry policy

| Phase | Max retries | Backoff |
|-------|-------------|---------|
| Planning | 3 | 5s, 30s, 5min |
| Rendering | 5 | 30s, 2min, 10min, 30min, 2h |
| QA | 2 | 1min, 10min |
| Publish | 3 | 1min, 5min, 30min |

---

## Phase checklist template

Copy for each new phase:

```markdown
### Phase X — [Name]

**Status:** [ ] Not started / [ ] In progress / [ ] Done
**Started:** YYYY-MM-DD
**Completed:** YYYY-MM-DD

#### Tasks
- [ ] X.1 — [description]
- [ ] X.2 — [description]

#### Vertical slice test
- [ ] Run Article → Video end-to-end
- [ ] [specific assertion for this phase]

#### Done Definition
- [ ] [criterion 1]
- [ ] [criterion 2]

#### Issues found
- [any ADR amendment needed]
```
