# ADR-005: Persistence Model

**Status:** Proposed  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-001, ADR-002, ADR-003, ADR-004

---

## Context

FilmOS introduces several new domain objects that must survive across:
- Multiple pipeline runs for the same production
- Server restarts and crashes
- Resume after partial failure (ADR-004)
- Analytics and billing queries
- Version history (a production's Bible can be revised)

The objects that need persistence decisions:

| Object | Where does it live now? | Problem |
|--------|------------------------|---------|
| `ProductionBible` | In-memory during pipeline | Lost on restart |
| `CharacterState` | In-memory per shot | No cross-shot tracking |
| `VisualMemory` entries | In-memory | Lost on restart, no retrieval |
| `ConstraintReport` | In-memory per scene | No audit trail |
| `PlanningContext` snapshot | Not persisted | No debug replay |
| `PromptIRSnapshot` | `PipelineRun` table (partial) | Incomplete |
| `EditDecisionList` | Not persisted | Cannot re-export |
| Pipeline events | Not persisted | No resume capability |

---

## Decision

Persist every domain object that must survive a process restart or enables
audit/resume/analytics. Use the **simplest storage that satisfies the requirement**
for each object — not one-size-fits-all.

---

## Storage Tiers

| Tier | Technology | When to use |
|------|-----------|-------------|
| T1 | Existing `pipeline_runs` table (JSON) | Outputs already stored there |
| T2 | New dedicated tables (normalized) | Domain objects with query needs |
| T3 | JSON blob in dedicated table | Complex objects, queried by ID only |
| T4 | File storage (`storage/filmos/`) | Large artifacts (images, videos, EDL files) |
| T5 | pgvector (Phase E3+) | Semantic embeddings for VisualMemory |

---

## Object-by-Object Decisions

### 1. ProductionBible

**Storage:** T3 — JSON blob per production version  
**Table:** `production_bibles`

```sql
CREATE TABLE production_bibles (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id VARCHAR(36) NOT NULL,
    version       VARCHAR(20) NOT NULL,   -- semver: "1.0.0", "1.1.0"
    status        ENUM('draft','locked') NOT NULL DEFAULT 'draft',
    payload       JSON NOT NULL,          -- serialized FrozenProductionBible
    locked_at     TIMESTAMP NULL,
    created_at    TIMESTAMP NOT NULL,
    updated_at    TIMESTAMP NOT NULL,
    UNIQUE KEY uq_production_version (production_id, version),
    KEY idx_production_status (production_id, status)
);
```

**Version policy:**
- `draft`: can be mutated (pre-`lock()`)
- `locked`: immutable — `lock()` creates a new `locked` record, never updates
- On `lock()`: `status = 'locked'`, `locked_at = now()`
- On revision: new row with incremented version, `status = 'draft'`

**Serialization:** `ProductionBible::toArray()` → JSON payload.
`ProductionBible::fromArray()` reconstructs on load.

```php
final class ProductionBibleRepository
{
    public function save(ProductionBible $bible, string $productionId): void { ... }
    public function loadLocked(string $productionId): FrozenProductionBible { ... }
    public function loadDraft(string $productionId): ProductionBible { ... }
    public function versions(string $productionId): array { ... }
}
```

---

### 2. CharacterState

**Storage:** T2 — normalized table, queried by characterId + shotId  
**Table:** `production_character_states`

```sql
CREATE TABLE production_character_states (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    scene_id       VARCHAR(36) NOT NULL,
    shot_id        VARCHAR(36) NOT NULL,
    character_id   VARCHAR(100) NOT NULL,
    emotion        VARCHAR(50) NOT NULL,
    pose           VARCHAR(100) NOT NULL,
    wardrobe       VARCHAR(500) NOT NULL,  -- "navy blazer, white blouse, black trousers"
    dirt_level     TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0=clean, 255=max
    wetness_level  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    injury_level   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    location_id    VARCHAR(100) NOT NULL,   -- world or asset where character is
    created_at     TIMESTAMP NOT NULL,
    KEY idx_production_character (production_id, character_id),
    KEY idx_shot (production_id, shot_id),
    KEY idx_scene_order (production_id, scene_id, shot_id)
);
```

**Query patterns:**
- "What was Character A's state in Shot 5?" → `WHERE shot_id = ?`
- "All states of Character A in Scene 3 in order" → `WHERE scene_id = ? AND character_id = ? ORDER BY shot_id`
- "Latest state of Character A before Shot 12" → complex, handled by `CharacterStateRepository`

```php
final class CharacterStateRepository
{
    public function stateAt(string $productionId, string $charId, string $shotId): CharacterState { ... }
    public function latestBefore(string $productionId, string $charId, string $shotId): ?CharacterState { ... }
    public function saveState(string $productionId, CharacterState $state): void { ... }
    public function statesForScene(string $productionId, string $sceneId): array { ... }
}
```

---

### 3. VisualMemory

**Storage:** T2 (Phase E1–E2) → T5 pgvector (Phase E3)  
**Tables:** `production_visual_memories` + `production_visual_memory_embeddings`

#### Phase E1–E2: Text-based, no vector search

```sql
CREATE TABLE production_visual_memories (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id   VARCHAR(36) NOT NULL,
    memory_type     ENUM('appearance','spatial','lighting','composition','asset') NOT NULL,
    entity_id       VARCHAR(100) NOT NULL,   -- characterId or assetId or worldId
    scene_id        VARCHAR(36) NOT NULL,
    shot_id         VARCHAR(36) NOT NULL,    -- first shot where this was established
    descriptor      TEXT NOT NULL,
    reference_image_id VARCHAR(100) NULL,
    is_locked       BOOLEAN NOT NULL DEFAULT FALSE,
    extra           JSON NULL,              -- type-specific data (CompositionEntry fields, etc.)
    created_at      TIMESTAMP NOT NULL,
    KEY idx_production_type_entity (production_id, memory_type, entity_id),
    KEY idx_scene_shot (production_id, scene_id, shot_id)
);
```

#### Phase E3: Add embedding column (migration, no table drop)

```sql
ALTER TABLE production_visual_memories
    ADD COLUMN embedding VECTOR(1536) NULL AFTER extra;

CREATE INDEX ON production_visual_memories
    USING hnsw (embedding vector_cosine_ops);
```

**Query patterns:**
- "AppearanceMemory for woman_protagonist" → `WHERE memory_type='appearance' AND entity_id=?`
- "Latest LightingMemory for scene_3" → `WHERE memory_type='lighting' AND scene_id=? ORDER BY shot_id DESC LIMIT 1`
- "5 most similar assets to this embedding" → `ORDER BY embedding <=> ? LIMIT 5` (Phase E3)

```php
final class VisualMemoryRepository
{
    public function record(string $productionId, MemoryEntry $entry): void { ... }
    public function findByEntity(string $productionId, MemoryType $type, string $entityId): ?MemoryEntry { ... }
    public function latestLighting(string $productionId, string $sceneId): ?LightingEntry { ... }
    public function compositionFor(string $productionId, string $sceneId): ?CompositionEntry { ... }
    public function assetsForScene(string $productionId, string $sceneId): array { ... }
    // Phase E3:
    public function findSimilar(string $productionId, array $embedding, int $topK = 5): array { ... }
}
```

---

### 4. ConstraintReport

**Storage:** T3 — JSON blob per scene  
**Table:** `production_constraint_reports`

```sql
CREATE TABLE production_constraint_reports (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    scene_id       VARCHAR(36) NOT NULL,
    bible_version  VARCHAR(20) NOT NULL,
    has_blockers   BOOLEAN NOT NULL,
    error_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    warning_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    payload        JSON NOT NULL,        -- full ConstraintReport serialized
    created_at     TIMESTAMP NOT NULL,
    KEY idx_production_scene (production_id, scene_id),
    KEY idx_has_blockers (production_id, has_blockers)
);
```

**Rationale:** Stored for:
1. Audit trail — what constraints passed/failed for this production
2. Resume — skip re-validation if scene hasn't changed
3. Analytics — which constraint types fail most often

---

### 5. PlanningContext Snapshot

**Storage:** T3 — JSON blob, **optional** (debug only)  
**Table:** `production_planning_snapshots`

```sql
CREATE TABLE production_planning_snapshots (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    shot_id        VARCHAR(36) NOT NULL,
    payload        JSON NOT NULL,          -- PlanningContext serialized (minus large blobs)
    created_at     TIMESTAMP NOT NULL,
    KEY idx_production_shot (production_id, shot_id)
) ENGINE=InnoDB ROW_FORMAT=COMPRESSED;
```

**Policy:**
- Only stored when `FILMOS_DEBUG_SNAPSHOTS=true` in `.env`
- Auto-purged after 7 days
- Used for: replay a specific shot's planning without re-running earlier pipeline stages

---

### 6. PromptIRSnapshot

**Storage:** Already in `pipeline_runs` table — **extend**, not replace

```sql
-- Add columns to existing pipeline_runs table
ALTER TABLE pipeline_runs
    ADD COLUMN afos_snapshot    JSON NULL COMMENT 'PromptIRSnapshot from AFOS',
    ADD COLUMN render_context   JSON NULL COMMENT 'RenderContext (Amendment A)',
    ADD COLUMN backend_id       VARCHAR(50) NULL,
    ADD COLUMN afos_version     VARCHAR(20) NULL;   -- AFOS semantic version
```

**Rationale:** `pipeline_runs` already exists and is used by `StoryPlanner`,
`SceneShotPlanner`. Extending it avoids a new table and keeps shot-level outputs together.

---

### 7. EditDecisionList

**Storage:** T4 — file + T3 metadata row  
**Table:** `production_edls` (metadata) + `storage/filmos/{productionId}/edl/` (files)

```sql
CREATE TABLE production_edls (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    version        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    total_shots    SMALLINT UNSIGNED NOT NULL,
    total_duration_ms INT UNSIGNED NOT NULL,
    edl_json_path  VARCHAR(500) NOT NULL,   -- path to storage/filmos/{id}/edl/v1.json
    fcpxml_path    VARCHAR(500) NULL,       -- exported Final Cut XML
    davinci_path   VARCHAR(500) NULL,       -- exported DaVinci EDL
    created_at     TIMESTAMP NOT NULL,
    UNIQUE KEY uq_production_version (production_id, version)
);
```

**File layout:**

```
storage/filmos/{productionId}/
├── edl/
│   ├── v1.json           ← EditDecisionList serialized
│   ├── v1.fcpxml         ← Final Cut Pro XML
│   └── v1.edl            ← DaVinci Resolve EDL
├── snapshots/
│   └── {shotId}.json     ← PromptIRSnapshot (if debug enabled)
└── memory/
    └── {productionId}.json  ← VisualMemory JsonSnapshotStore (Phase E2)
```

---

### 8. Production Events

**Storage:** T2 — see ADR-004  
**Table:** `production_events` (defined in ADR-004)

```sql
-- Full schema (reference from ADR-004)
CREATE TABLE production_events (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    event_type     VARCHAR(200) NOT NULL,
    scene_id       VARCHAR(36) NULL,
    shot_id        VARCHAR(36) NULL,
    payload        JSON NOT NULL,
    status         ENUM('emitted','processed','failed') NOT NULL DEFAULT 'emitted',
    attempt        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL,
    processed_at   TIMESTAMP NULL,
    KEY idx_production_status (production_id, status),
    KEY idx_production_scene (production_id, scene_id),
    KEY idx_production_shot (production_id, shot_id),
    KEY idx_event_type (event_type)
);
```

**TTL policy:** Events older than 90 days with `status='processed'` are archived
to cold storage (S3 or equivalent). Events with `status='failed'` are kept indefinitely
for manual review.

---

## Full Schema Summary

```
Tables (new):
  production_bibles              ← ProductionBible versions
  production_character_states    ← CharacterState per shot
  production_visual_memories     ← VisualMemory entries (all 5 types)
  production_constraint_reports  ← ConstraintReport per scene
  production_planning_snapshots  ← PlanningContext debug snapshots (optional)
  production_edls                ← EditDecisionList metadata
  production_events              ← ADR-004 event log

Tables (extended):
  pipeline_runs                  ← add afos_snapshot, render_context, backend_id

Files (new):
  storage/filmos/{productionId}/edl/
  storage/filmos/{productionId}/snapshots/   (optional, debug)
  storage/filmos/{productionId}/memory/      (Phase E2)
```

---

## Repository Pattern

All database access goes through repositories. Domain objects never import
Eloquent models directly.

```php
namespace App\Services\AI\FilmOS\Persistence;

interface ProductionBibleRepository
{
    public function save(ProductionBible $bible, string $productionId): void;
    public function loadLocked(string $productionId): FrozenProductionBible;
    public function loadDraft(string $productionId): ?ProductionBible;
}

interface CharacterStateRepository
{
    public function stateAt(string $productionId, string $charId, string $shotId): CharacterState;
    public function latestBefore(string $productionId, string $charId, string $shotId): ?CharacterState;
    public function saveState(string $productionId, CharacterState $state): void;
}

interface VisualMemoryRepository
{
    public function record(string $productionId, MemoryEntry $entry): void;
    public function findByEntity(string $productionId, MemoryType $type, string $entityId): ?MemoryEntry;
    public function latestLighting(string $productionId, string $sceneId): ?LightingEntry;
    public function compositionFor(string $productionId, string $sceneId): ?CompositionEntry;
}
```

Implementations:
- `EloquentProductionBibleRepository` (uses `production_bibles` table)
- `InMemoryProductionBibleRepository` (for tests — no DB required)

---

## Migration Plan

All new tables are additive — no existing tables modified except `pipeline_runs`.

```
Phase B1:  production_bibles
Phase B3:  production_character_states
Phase B5:  production_constraint_reports
Phase B7:  production_planning_snapshots (optional)
Phase B8:  pipeline_runs extension (afos_snapshot, render_context)
Phase D:   production_events (ADR-004)
Phase E1:  production_visual_memories (no embedding column)
Phase E2:  production_edls + storage/filmos/ layout
Phase E3:  ALTER production_visual_memories ADD COLUMN embedding VECTOR(1536)
```

---

## Consequences

### Positive
- `CharacterState` can be queried across shots → ConstraintEngine can check
  "what was she wearing in Shot 3 vs Shot 12" without re-running planning
- `ProductionBible` versioning enables "undo" a style change and re-compile
- `VisualMemory` persisted → pipeline can resume mid-production without losing
  the appearance/lighting/composition locks established in earlier shots
- `ConstraintReport` stored → analytics on which constraints fail most (guides
  future library improvements)
- All domain objects use Repository pattern → unit tests never touch a database

### Negative
- 8 new tables adds migration overhead per production deployment
- `production_visual_memories` can grow large for multi-scene productions
  (mitigated by archival after production completes)
- pgvector requires PostgreSQL ≥ 15 or a separate vector DB — not needed until Phase E3

### Not changing
- AFOS internal state (no persistence needed — `PromptIRSnapshot` is output only)
- `pipeline_runs` table semantics — only extended, not restructured
- Existing `PipelineRun` Eloquent model — extended, not replaced

---

## Open Questions (resolved)

1. **SceneNode vs SceneDTO at API boundary:** Both coexist. `SceneDTO` stays at
   the Laravel controller boundary. `SceneNode` is the internal FilmOS canonical form.
   `SceneNodeRepository` does not exist — `SceneNode` is derived from `SceneDTO` +
   `ProductionBible` on each run. Only `CharacterState` (dynamic) needs its own table.

2. **CharacterState persistence granularity:** Per-shot is the right granularity.
   Querying "latest state before Shot N" covers all use cases.

3. **AssetInstance state ownership:** `AssetInstance.state` is input to `ConstraintEngine`
   (read) and updated by `StateTransitionConstraint` validation (write, only if valid).
   Stored in `production_visual_memories` with `memory_type='asset'`.

---

## References

- ADR-002: FilmOS Unified Model (ProductionBible, CharacterState, AssetInstance)
- ADR-003: FilmOS Extended Engines (VisualMemory sub-stores)
- ADR-004: Production Event Bus (production_events table)
- Existing: `database/migrations/` — `pipeline_runs` table
- Existing: `app/Models/PipelineRun.php` — extended in Phase B8
