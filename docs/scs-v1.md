# System Contract Specification v1

**Date:** 2026-07-07  
**Status:** ACTIVE  
**Scope:** All modules in `app/Services/AI/`

> ADRs answer "what did we decide." SCS answers "how must modules talk to each other."  
> Violating an ADR is a design debt. Violating the SCS is a boundary breach — fix immediately.

---

## Contract 1 — AFOS ↔ FilmOS Boundary

**Owner:** AFOS is a compiler. FilmOS is a domain model. They must not know each other's internals.

### FilmOS → AFOS (inputs)

| What FilmOS passes | Type | Where defined |
|--------------------|------|---------------|
| Shot brief | `ShotGoalIR` | `AFOS\Ir\ShotGoalIR` |
| Director style | `DirectorProfile` | `AFOS\Creative\DirectorProfile` |
| Cinematography style | `CinematographyProfile` | `AFOS\Creative\CinematographyProfile` |
| Creative intent | `Intent` | `AFOS\Creative\Intent` |
| World context (optional) | `RenderContext` | `AFOS\Ir\RenderContext` (ADR-002 Amendment A) |

**Rule:** FilmOS constructs these objects via `fromArray()` factories. FilmOS never constructs AFOS internal objects (stages, passes, registry entries).

### AFOS → FilmOS (outputs)

| What AFOS returns | Type |
|-------------------|------|
| Compiled prompt snapshot | `PromptIRSnapshot` |
| Or raw prompt string | `string` (via `AfosPassManager::compile()`) |

**Rule:** AFOS never throws a recoverable exception to FilmOS. Compilation errors are returned in `DiagnosticBag`. Only `\LogicException` (programmer error) or `\RuntimeException` (unrecoverable) may escape.

### Import rules

```
✓ FilmOS MAY import: AFOS\Ir\*, AFOS\Creative\*, AFOS\Production\*
✗ FilmOS MUST NOT import: AFOS\Passes\*, AFOS\Backends\*, AFOS\Compiler\*
✓ AFOS MAY import: nothing from FilmOS
✗ AFOS MUST NOT import: anything from app\Services\AI\ outside AFOS\
```

**Bridge class:** `AFOS\Planning\ShotGoalIRAdapter` is the only permitted legacy-to-AFOS translation layer. When Phase B ProductionBible is ready, the adapter is replaced by native FilmOS constructors. Do not add business logic to the adapter.

---

## Contract 2 — FilmOS ↔ Provider Runtime Boundary

**Owner:** FilmOS describes what it needs. Provider Runtime decides which model delivers it.

### FilmOS → Provider (request)

FilmOS issues a `CapabilitySpec` (ADR-007). It never names a model.

```php
// CORRECT — capability-first
$spec = new CapabilitySpec(
    mediaType: MediaType::VIDEO,
    minDurationSec: 5,
    maxDurationSec: 10,
    requiredFeatures: [Feature::MOTION_CONTROL, Feature::CINEMATIC_CAMERA],
);

// WRONG — model-first, violates SCS
$provider = new KlingVideoGenerator();
```

### Provider → FilmOS (response)

Provider Runtime returns a `RenderedAsset`:

```php
readonly class RenderedAsset {
    public string $assetId;
    public string $url;
    public MediaType $mediaType;
    public ProviderProfile $resolvedProvider;  // which model was used (for audit)
    public float $costUsd;
}
```

**Rule:** FilmOS only reads `assetId`, `url`, `mediaType`. It must not branch on `resolvedProvider->name`.

---

## Contract 3 — Production Event Bus

**Owner:** `ProductionEventBus` (ADR-004). Any module may emit. Any module may subscribe.

### Event naming

```
Format: PastTense noun phrase, PascalCase
Good:   ShotDecided, ScenePlanned, BibleLocked, VideoRendered, ProductionFailed
Bad:    ShotIsDeciding, RenderVideo, shot_decided
```

### Event structure

```php
interface ProductionEvent
{
    public function eventId(): string;      // UUID v4
    public function occurredAt(): string;   // ISO 8601
    public function productionId(): string; // links event to production run
    public function toArray(): array;       // for persistence + replay
}
```

### Handler rules

```
✓ Handlers dispatch to domain services
✓ Handlers are idempotent (safe to replay)
✗ Handlers contain business logic
✗ Handlers call other handlers directly
✗ Handlers throw exceptions that abort the bus
```

---

## Contract 4 — Repository Pattern

**Owner:** All persistence goes through repositories. No direct Eloquent in domain services.

### Interface location

```
Domain interface:       app/Services/AI/FilmOS/Repositories/ProductionBibleRepository.php
Infrastructure impl:    app/Repositories/Eloquent/EloquentProductionBibleRepository.php
```

### Repository rules

```
✓ Repositories accept domain IDs (string UUIDs), not Eloquent primary keys
✓ Repositories return domain objects, not Eloquent models
✓ Repositories are injected via interface, not concrete class
✗ Domain services import Eloquent models
✗ Repositories contain business logic
✗ Repositories call other repositories in a chain
```

### Standard interface shape

```php
interface ProductionBibleRepository
{
    public function findById(string $id): ?ProductionBible;
    public function save(ProductionBible $bible): void;
    public function findByProductionId(string $productionId): ?ProductionBible;
}
```

---

## Contract 5 — DTO / Value Object Rules

All data transfer objects follow this standard:

```php
final readonly class ExampleDTO
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(id: $data['id'], name: $data['name']);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
```

**Rules:**
- `final` — DTOs are not subclassed
- `readonly` — DTOs are immutable after construction
- `fromArray()` — standard deserialization entry point
- `toArray()` — standard serialization for persistence + events
- No business logic (no `compile()`, `validate()`, `render()` methods)
- No dependencies on services or repositories

---

## Contract 6 — Naming Conventions

| Suffix | Meaning | Example |
|--------|---------|---------|
| `IR` | Intermediate Representation (AFOS compiler artifact) | `ShotGoalIR`, `CameraIR`, `PromptIR` |
| `Module` | FilmOS aggregate sub-component | `WorldModule`, `CharacterModule`, `AssetModule` |
| `Stage` | AFOS compiler stage | `Tier1Stage`, `FreezeStage`, `BackendStage` |
| `Pass` | AFOS domain algorithm | `KlingPromptPlanningPass`, `SimpleCameraPass` |
| `Profile` | Style/config value object | `DirectorProfile`, `CinematographyProfile` |
| `Engine` | Stateless rule evaluator | `ConstraintEngine`, `VisualLanguageEngine` |
| `Planner` | LLM or rule-based producer of plans | `StoryPlanner`, `SceneShotPlanner` |
| `Resolver` | Dispatcher that selects a strategy | `PlannerResolver`, `CapabilityResolver` |
| `Registry` | Immutable map of strategies | `BackendRegistry`, `PlannerRegistry` |
| `Adapter` | One-way translation between boundaries | `ShotGoalIRAdapter` |
| `Repository` | Persistence abstraction | `ProductionBibleRepository` |
| `Spec` | Declarative requirement (no implementation) | `CapabilitySpec` |
| `View` | Read-only sub-interface (narrow access) | `TrackCollectionView`, `TemporalGraphView` |

---

## Contract 7 — File Organization

```
app/Services/AI/
├── AFOS/              ← Compiler only. No business logic.
│   ├── Ir/            ← Public IR types (cross-boundary safe)
│   ├── Creative/      ← Public creative inputs (cross-boundary safe)
│   ├── Production/    ← Public production state (cross-boundary safe)
│   ├── Planning/      ← Bridge adapters (Phase A only)
│   └── Passes/        ← Internal. FilmOS must not import.
│
├── FilmOS/            ← Domain model. Phase B onwards.
│   ├── Core/          ← ProductionBible, WorldModule, CharacterModule...
│   ├── Engines/       ← ConstraintEngine, VisualLanguageEngine...
│   ├── Repositories/  ← Repository interfaces (domain side)
│   └── Events/        ← ProductionEvent implementations
│
├── Providers/         ← Provider Runtime. Phase G onwards.
│   ├── Capability/    ← CapabilitySpec, CapabilityResolver
│   └── Adapters/      ← Kling, Veo, Runway plugin implementations
│
└── [legacy]/          ← StoryPlanner, ScenePlanner, SceneGraph, PromptCompiler
                          Frozen in place. Do not add features. Replace in Phase B.
```

---

## Enforcement

Run this grep to detect boundary violations before committing:

```bash
# AFOS must not import FilmOS
grep -rn "use App\\Services\\AI\\FilmOS" app/Services/AI/AFOS/ --include="*.php"

# FilmOS must not import AFOS internals  
grep -rn "use App\\Services\\AI\\AFOS\\Passes" app/Services/AI/FilmOS/ --include="*.php"
grep -rn "use App\\Services\\AI\\AFOS\\Backends" app/Services/AI/FilmOS/ --include="*.php"
grep -rn "use App\\Services\\AI\\AFOS\\Compiler" app/Services/AI/FilmOS/ --include="*.php"
```

Add these to the CI pre-push hook.
