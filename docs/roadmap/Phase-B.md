# Phase B — FilmOS Core

**Status:** NOT STARTED — starts after Phase A.5 Done Definition passes  
**Priority:** P1 — unlocks Phase C through H  
**Estimate:** 6–8 weeks  
**Depends on:** Phase A.5 (DONE)  
**ADR references:** ADR-002 (Amendments A, B, C, D), ADR-005

---

## Why Phase B before C–H

Without `ProductionBible`, `CharacterDefinition`, and `CharacterState`:

- `CharacterBrain` (Phase D) has no character definitions to load
- `VisualMemory` (Phase E) has no asset anchors to store
- `EditingOS` (Phase F) has no shot metadata to schedule
- `DirectorOS` (Phase G) has no world state to reason over

Phase B is the **data foundation**. Everything else depends on it.

---

## Dependency graph

```
B1-001 (ProductionBible core)
    ├── B1-002 (FrozenProductionBible)
    ├── B1-003 (ProductionBibleRepository interface)
    └── B1-004 (EloquentProductionBibleRepository + migration)
            ↓
B2-001 (WorldModel + Location)
    ├── B2-002 (LocationState)
    └── B2-003 (WorldModule)
            ↓
B3-001 (CharacterDefinition)
    ├── B3-002 (CharacterState)
    ├── B3-003 (AppearanceAnchor)
    └── B3-004 (CharacterModule)
            ↓
B4-001 (AssetDefinition)
    ├── B4-002 (AssetInstance)
    └── B4-003 (AssetModule)
            ↓
B5-001 (StyleBible value objects × 7)
    └── B5-002 (StyleModule)
            ↓
B6-001 (ConstraintViolation + ConstraintReport)
    ├── B6-002 (PhysicsConstraint)
    ├── B6-003 (ContinuityConstraint)
    ├── B6-004 (SemanticConstraint)
    ├── B6-005 (CameraConstraint)
    ├── B6-006 (LightingConstraint)
    ├── B6-007 (EmotionConstraint)
    ├── B6-008 (PresenceConstraint)
    ├── B6-009 (TemporalConstraint)
    └── B6-010 (ConstraintEngine)
            ↓
B7-001 (SceneNode)
    └── B7-002 (ShotNode + SceneGraphV2)
            ↓
B8-001 (ShotContext)
    ├── B8-002 (VisualContext)
    ├── B8-003 (CharacterContext)
    ├── B8-004 (MotionContext)
    ├── B8-005 (EditingContext)
    ├── B8-006 (PlanningContext aggregate)
    └── B8-007 (PlanningContextBuilder)
            ↓
B9-001 (Wire FrozenProductionBible into GraphAssembler)
    └── B9-002 (Fire ScenePlanned + BibleLocked events)
```

---

## Week 1 — ProductionBible (B1)

### B1-001 — ProductionBible core

**Estimate:** 1.5 days  
**Depends on:** none

**Files to create:**
```
app/Services/AI/FilmOS/Core/ProductionBible.php
tests/Unit/FilmOS/Core/ProductionBibleTest.php
```

**ProductionBible contract:**
```php
final class ProductionBible
{
    public static function draft(string $productionId): self;

    public function withStyle(StyleModule $style): self;
    public function withWorld(WorldModule $world): self;
    public function withCharacters(CharacterModule $characters): self;
    public function withAssets(AssetModule $assets): self;

    public function lock(): FrozenProductionBible;   // BUILD → LOCKED, immutable after this

    public function productionId(): string;
    public function version(): int;
    public function isLocked(): bool;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

**Invariants to test:**
- `lock()` on an already-locked bible throws `\LogicException`
- `with*()` on a locked bible throws `\LogicException`
- `version` increments each time `with*()` is called
- `toArray()` → `fromArray()` round-trip is lossless

**Done:** `ProductionBible::draft($id)->withStyle($s)->lock()` returns a `FrozenProductionBible`.

---

### B1-002 — FrozenProductionBible

**Estimate:** 0.5 day  
**Depends on:** B1-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/FrozenProductionBible.php
```

`FrozenProductionBible` is the object passed to `GraphAssembler` and all downstream stages. It exposes only read-only getters. No mutation methods.

```php
final readonly class FrozenProductionBible
{
    public function productionId(): string;
    public function version(): int;
    public function lockedAt(): \DateTimeImmutable;
    public function style(): StyleModule;
    public function world(): WorldModule;
    public function characters(): CharacterModule;
    public function assets(): AssetModule;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

**Done:** `FrozenProductionBible` is readonly, has no mutation methods, serializes correctly.

---

### B1-003 — ProductionBibleRepository interface

**Estimate:** 0.25 day  
**Depends on:** B1-001

**Files to create:**
```
app/Services/AI/FilmOS/Repositories/ProductionBibleRepository.php
```

```php
interface ProductionBibleRepository
{
    public function findById(string $id): ?ProductionBible;
    public function findFrozenByProductionId(string $productionId): ?FrozenProductionBible;
    public function save(ProductionBible $bible): void;
    public function saveFrozen(FrozenProductionBible $frozen): void;
}
```

**Done:** Interface exists. No implementation yet.

---

### B1-004 — Eloquent implementation + migration

**Estimate:** 1 day  
**Depends on:** B1-003

**Files to create:**
```
app/Repositories/Eloquent/EloquentProductionBibleRepository.php
app/Models/ProductionBibleModel.php         (Eloquent model, internal only)
database/migrations/xxxx_create_production_bibles_table.php
```

**Schema:**
```sql
production_bibles (
    id             UUID PRIMARY KEY,
    production_id  UUID NOT NULL,
    version        INT NOT NULL DEFAULT 1,
    locked_at      TIMESTAMP NULL,
    style_json     JSON NOT NULL DEFAULT '{}',
    world_json     JSON NOT NULL DEFAULT '{}',
    character_json JSON NOT NULL DEFAULT '{}',
    asset_json     JSON NOT NULL DEFAULT '{}',
    created_at     TIMESTAMP,
    updated_at     TIMESTAMP,
    INDEX idx_production_id (production_id)
)
```

**Done:** `EloquentProductionBibleRepository::save()` persists to DB. `findFrozenByProductionId()` reloads.

---

## Week 2 — WorldModule (B2)

### B2-001 — WorldModel + Location

**Estimate:** 1 day  
**Depends on:** B1-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/World/Location.php
app/Services/AI/FilmOS/Core/World/WorldModel.php
tests/Unit/FilmOS/Core/World/WorldModelTest.php
```

**Location contract:**
```php
final readonly class Location
{
    public static function define(
        string $locationId,
        string $description,
        string $lightingDefault = 'natural',
        array  $objectsPresent  = [],
    ): self;

    public function locationId(): string;
    public function description(): string;
    public function lightingDefault(): string;
    public function objectsPresent(): array;
}
```

**WorldModel:** value object holding a map of `locationId → Location`.

**Done:** `WorldModel::empty()->addLocation(Location::define(...))` is chainable and immutable.

---

### B2-002 — LocationState

**Estimate:** 0.5 day  
**Depends on:** B2-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/World/LocationState.php
app/Services/AI/FilmOS/Core/World/LightingTransition.php
```

`LocationState` tracks mutable state of a location at a specific shot: current lighting, time of day, which objects are present/broken/moved.

**Done:** `LocationState` correctly applies transitions and is serializable.

---

### B2-003 — WorldModule

**Estimate:** 0.5 day  
**Depends on:** B2-001, B2-002

**Files to create:**
```
app/Services/AI/FilmOS/Core/World/WorldModule.php
tests/Unit/FilmOS/Core/World/WorldModuleTest.php
```

```php
final class WorldModule
{
    public static function empty(): self;
    public function addLocation(Location $location): self;          // immutable
    public function stateAt(string $locationId, string $shotId): LocationState;
    public function applyTransition(string $locationId, string $shotId, LightingTransition $t): self;
    public function locations(): array;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

**Done:** `WorldModule::stateAt('lobby', 'shot_003')` returns consistent state across shots.

---

## Week 3 — CharacterModule (B3)

### B3-001 — CharacterDefinition

**Estimate:** 0.5 day  
**Depends on:** none (parallel with B2)

**Files to create:**
```
app/Services/AI/FilmOS/Core/Character/CharacterDefinition.php
tests/Unit/FilmOS/Core/Character/CharacterDefinitionTest.php
```

**Fields:** `characterId`, `name`, `appearanceDescription` (Kling-ready prompt fragment), `defaultEmotion`, `costumeByScene` (array: sceneId → costume description)

**Done:** `CharacterDefinition::fromArray()` → `toArray()` round-trip tested.

---

### B3-002 — CharacterState

**Estimate:** 0.5 day  
**Depends on:** B3-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/Character/CharacterState.php
```

**Fields:** `characterId`, `shotId`, `emotion`, `locationId`, `isPresent`, `postureHint`, `lookDirection`

Immutable. Apply transitions to get new state.

**Done:** `CharacterState` serializes and deserializes. Transitions produce new instances.

---

### B3-003 — AppearanceAnchor

**Estimate:** 0.25 day  
**Depends on:** B3-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/Character/AppearanceAnchor.php
```

Carries the appearance description that will be injected into the AFOS `RenderContext` (ADR-002 Amendment A). Ensures the same character looks identical across shots.

```php
final readonly class AppearanceAnchor
{
    public string $characterId;
    public string $appearancePromptFragment;  // injected into Kling prompt
    public string $lockedAtShotId;            // first shot where appearance was confirmed
}
```

**Done:** `AppearanceAnchor` exists. Used by `CharacterModule` when queried.

---

### B3-004 — CharacterModule

**Estimate:** 1 day  
**Depends on:** B3-001, B3-002, B3-003

**Files to create:**
```
app/Services/AI/FilmOS/Core/Character/CharacterModule.php
tests/Unit/FilmOS/Core/Character/CharacterModuleTest.php
```

```php
final class CharacterModule
{
    public static function empty(): self;
    public function define(CharacterDefinition $def): self;           // immutable
    public function stateAt(string $characterId, string $shotId): CharacterState;
    public function recordTransition(string $characterId, string $shotId, array $changes): self;
    public function appearanceAnchorFor(string $characterId): ?AppearanceAnchor;
    public function allCharacterIds(): array;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

**Done:** A character defined in shot 1 has consistent appearance in shot 7.

---

## Week 4 — AssetModule + StyleModule (B4, B5)

### B4-001 — AssetDefinition + AssetInstance

**Estimate:** 0.5 day  
**Depends on:** none

**Files to create:**
```
app/Services/AI/FilmOS/Core/Asset/AssetDefinition.php
app/Services/AI/FilmOS/Core/Asset/AssetInstance.php
```

- `AssetDefinition`: `assetId`, `name`, `category` (prop/vehicle/location/costume), `descriptionPromptFragment`
- `AssetInstance`: `assetId`, `shotId`, `state` (intact/damaged/open/closed/on/off), `visibilityHint`

**Done:** Assets serialized correctly.

---

### B4-002 — AssetModule

**Estimate:** 0.5 day  
**Depends on:** B4-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/Asset/AssetModule.php
tests/Unit/FilmOS/Core/Asset/AssetModuleTest.php
```

**Done:** `AssetModule::stateAt('kettle', 'shot_004')` returns `AssetInstance` with correct state.

---

### B5-001 — StyleBible value objects

**Estimate:** 1.5 days  
**Depends on:** none

**Files to create:**
```
app/Services/AI/FilmOS/Core/Style/LensBible.php
app/Services/AI/FilmOS/Core/Style/LightingBible.php
app/Services/AI/FilmOS/Core/Style/CompositionBible.php
app/Services/AI/FilmOS/Core/Style/MovementBible.php
app/Services/AI/FilmOS/Core/Style/ColorBible.php
app/Services/AI/FilmOS/Core/Style/FocusBible.php
app/Services/AI/FilmOS/Core/Style/TransitionBible.php
tests/Unit/FilmOS/Core/Style/StyleBibleTest.php
```

Each Bible is a readonly value object with defaults + customization. Example:

```php
final readonly class LensBible
{
    public function __construct(
        public array  $preferredFocalLengthsMm = [35, 85],
        public string $depthOfFieldPreference   = 'shallow',
        public string $zoomPolicy               = 'optical_only',
    ) {}

    public static function defaults(): self;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

**Done:** All 7 Bibles exist with defaults. `fromArray(toArray($x)) === $x`.

---

### B5-002 — StyleModule

**Estimate:** 0.5 day  
**Depends on:** B5-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/Style/StyleModule.php
```

```php
final readonly class StyleModule
{
    public static function defaults(): self;
    public function withLens(LensBible $lens): self;
    // ... withLighting, withComposition, withMovement, withColor, withFocus, withTransition
    public function lens(): LensBible;
    // ... lighting(), composition(), movement(), color(), focus(), transition()
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

**Done:** `StyleModule::defaults()` returns all 7 Bibles. Fully serializable.

---

## Week 5 — ConstraintEngine (B6)

### B6-001 — ConstraintViolation + ConstraintReport

**Estimate:** 0.5 day  
**Depends on:** none

**Files to create:**
```
app/Services/AI/FilmOS/Engines/Constraint/ConstraintViolation.php
app/Services/AI/FilmOS/Engines/Constraint/ConstraintReport.php
```

```php
final readonly class ConstraintViolation
{
    public string $constraintName;
    public string $message;
    public bool   $isBlocker;   // true = cannot proceed; false = warning only
    public array  $context;
}

final class ConstraintReport
{
    public function hasBlockers(): bool;
    public function blockers(): array;         // ConstraintViolation[]
    public function warnings(): array;         // ConstraintViolation[]
    public function all(): array;
    public function isEmpty(): bool;
}
```

**Done:** `ConstraintReport` can be built, queried, serialized.

---

### B6-002 through B6-009 — 8 individual constraints

**Estimate:** 2 days total (0.25 day each)  
**Depends on:** B6-001, B3-004 (CharacterModule), B2-003 (WorldModule)

**Files to create:**
```
app/Services/AI/FilmOS/Engines/Constraint/
├── PhysicsConstraint.php          B6-002
├── ContinuityConstraint.php       B6-003
├── SemanticConstraint.php         B6-004
├── CameraConstraint.php           B6-005
├── LightingConstraint.php         B6-006
├── EmotionConstraint.php          B6-007
├── PresenceConstraint.php         B6-008
└── TemporalConstraint.php         B6-009
```

Each constraint implements:
```php
interface ConstraintRule
{
    public function name(): string;
    public function evaluate(PlanningContext $ctx): ConstraintReport;
}
```

**Minimum one failing test per constraint** (show what it blocks).

**Done:** All 8 constraints evaluate. At least 3 block their known-bad inputs.

---

### B6-010 — ConstraintEngine

**Estimate:** 0.5 day  
**Depends on:** B6-002 through B6-009

**Files to create:**
```
app/Services/AI/FilmOS/Engines/Constraint/ConstraintEngine.php
tests/Unit/FilmOS/Engines/ConstraintEngineTest.php
```

```php
final class ConstraintEngine
{
    public static function withDefaults(): self;
    public function addRule(ConstraintRule $rule): self;    // immutable
    public function evaluate(PlanningContext $ctx): ConstraintReport;
}
```

**Done:** `ConstraintEngine::withDefaults()->evaluate($ctx)` runs all 8 constraints and returns merged report.

---

## Week 6 — SceneGraphV2 (B7)

### B7-001 — SceneNode

**Estimate:** 0.5 day  
**Depends on:** B2-003 (WorldModule)

**Files to create:**
```
app/Services/AI/FilmOS/Core/SceneGraph/SceneNode.php
```

**Fields:** `sceneId`, `title`, `locationId`, `timeOfDay`, `emotionalArc`, `shotIds[]`

**Done:** `SceneNode` serializes correctly.

---

### B7-002 — ShotNode + SceneGraphV2

**Estimate:** 1 day  
**Depends on:** B7-001

**Files to create:**
```
app/Services/AI/FilmOS/Core/SceneGraph/ShotNode.php
app/Services/AI/FilmOS/Core/SceneGraph/SceneGraphV2.php
tests/Unit/FilmOS/Core/SceneGraph/SceneGraphV2Test.php
```

**ShotNode fields:** `shotId`, `sceneId`, `index`, `durationSec`, `goalType`, `emotion`, `characterIds[]`, `assetIds[]`, `cameraMove`

**SceneGraphV2:** ordered list of SceneNodes, each containing ordered ShotNodes. Validates that character/asset IDs exist in the Bible.

**Done:** A 3-scene, 9-shot production graph can be built and validated.

---

## Week 7 — PlanningContext (B8)

### B8-001 through B8-006 — 5 sub-contexts + aggregate

**Estimate:** 2 days  
**Depends on:** B2-003, B3-004, B4-002, B5-002, B7-002

**Files to create:**
```
app/Services/AI/FilmOS/Core/Planning/ShotContext.php        B8-001
app/Services/AI/FilmOS/Core/Planning/VisualContext.php      B8-002
app/Services/AI/FilmOS/Core/Planning/CharacterContext.php   B8-003
app/Services/AI/FilmOS/Core/Planning/MotionContext.php      B8-004
app/Services/AI/FilmOS/Core/Planning/EditingContext.php     B8-005
app/Services/AI/FilmOS/Core/Planning/PlanningContext.php    B8-006
tests/Unit/FilmOS/Core/Planning/PlanningContextTest.php
```

`PlanningContext` is the aggregate passed to `ConstraintEngine` and `ShotGoalIRAdapter` (replacing the flat legacy context object):

```php
final readonly class PlanningContext
{
    public ShotContext      $shot;
    public VisualContext    $visual;
    public CharacterContext $character;
    public MotionContext    $motion;
    public EditingContext   $editing;

    public function forAfos(): array;   // returns data ShotGoalIRAdapter needs
}
```

**Done:** `PlanningContext` passes through `ConstraintEngine` without throwing on valid input.

---

### B8-007 — PlanningContextBuilder

**Estimate:** 1 day  
**Depends on:** B8-006, B6-010

**Files to create:**
```
app/Services/AI/FilmOS/Core/Planning/PlanningContextBuilder.php
tests/Unit/FilmOS/Core/Planning/PlanningContextBuilderTest.php
```

```php
final class PlanningContextBuilder
{
    public function __construct(private FrozenProductionBible $bible) {}

    public function forShot(ShotNode $shot): PlanningContext;
}
```

Builder reads `CharacterModule`, `WorldModule`, `AssetModule`, `StyleModule` from the bible and populates all sub-contexts for a given shot.

**Done:** `PlanningContextBuilder::forShot($shotNode)` returns a fully populated `PlanningContext` in < 5ms.

---

## Week 8 — Wire everything (B9)

### B9-001 — Wire FrozenProductionBible into GraphAssembler

**Estimate:** 1 day  
**Depends on:** B8-007, B1-004

**File to modify:**
```
app/Services/AI/SceneGraph/GraphAssembler.php
```

**Changes:**
1. Accept `FrozenProductionBible` as constructor argument (optional for backward compat)
2. When bible is present: replace raw ScenePlanningResult processing with `PlanningContextBuilder::forShot()`
3. Pass `PlanningContext` to `ShotGoalIRAdapter` (update adapter to accept PlanningContext OR legacy result)
4. Run `ConstraintEngine::withDefaults()->evaluate($ctx)` — block shot if has blockers

**Done:** GraphAssembler uses FrozenProductionBible when available. Legacy path still works for Phase A.5 shots.

---

### B9-002 — Fire ScenePlanned + BibleLocked events

**Estimate:** 0.5 day  
**Depends on:** B9-001

**Files to create:**
```
app/Services/AI/FilmOS/Events/BibleLocked.php
app/Services/AI/FilmOS/Events/ScenePlanned.php
```

Both implement `ProductionEvent` (ADR-004 interface). Dispatched via Laravel's event bus for now; full Production Event Bus in Phase G.

**Done:** `BibleLocked` and `ScenePlanned` events are fired. Listeners can be registered.

---

## Done Definition for Phase B

- [ ] `FrozenProductionBible` can be saved to DB and reloaded from it
- [ ] A 3-scene, 9-shot production runs `PlanningContextBuilder::forShot()` for each shot
- [ ] `ConstraintEngine` blocks at least one physically impossible shot (character in two locations)
- [ ] Character X has consistent appearance description in shot 1 and shot 7
- [ ] Location lighting state is consistent across shots in the same scene
- [ ] `GraphAssembler` accepts `FrozenProductionBible` and produces the same video URLs as Phase A.5
- [ ] `BibleLocked` and `ScenePlanned` events fire

---

## Files created by Phase B

```
app/Services/AI/FilmOS/
├── Core/
│   ├── ProductionBible.php
│   ├── FrozenProductionBible.php
│   ├── World/
│   │   ├── Location.php
│   │   ├── LocationState.php
│   │   ├── LightingTransition.php
│   │   └── WorldModule.php
│   ├── Character/
│   │   ├── CharacterDefinition.php
│   │   ├── CharacterState.php
│   │   ├── AppearanceAnchor.php
│   │   └── CharacterModule.php
│   ├── Asset/
│   │   ├── AssetDefinition.php
│   │   ├── AssetInstance.php
│   │   └── AssetModule.php
│   ├── Style/
│   │   ├── LensBible.php
│   │   ├── LightingBible.php
│   │   ├── CompositionBible.php
│   │   ├── MovementBible.php
│   │   ├── ColorBible.php
│   │   ├── FocusBible.php
│   │   ├── TransitionBible.php
│   │   └── StyleModule.php
│   ├── SceneGraph/
│   │   ├── SceneNode.php
│   │   ├── ShotNode.php
│   │   └── SceneGraphV2.php
│   └── Planning/
│       ├── ShotContext.php
│       ├── VisualContext.php
│       ├── CharacterContext.php
│       ├── MotionContext.php
│       ├── EditingContext.php
│       ├── PlanningContext.php
│       └── PlanningContextBuilder.php
├── Engines/
│   └── Constraint/
│       ├── ConstraintViolation.php
│       ├── ConstraintReport.php
│       ├── ConstraintRule.php       (interface)
│       ├── ConstraintEngine.php
│       ├── PhysicsConstraint.php
│       ├── ContinuityConstraint.php
│       ├── SemanticConstraint.php
│       ├── CameraConstraint.php
│       ├── LightingConstraint.php
│       ├── EmotionConstraint.php
│       ├── PresenceConstraint.php
│       └── TemporalConstraint.php
├── Events/
│   ├── BibleLocked.php
│   └── ScenePlanned.php
└── Repositories/
    └── ProductionBibleRepository.php   (interface)
app/Repositories/Eloquent/
└── EloquentProductionBibleRepository.php
app/Models/
└── ProductionBibleModel.php
database/migrations/
└── xxxx_create_production_bibles_table.php
```
