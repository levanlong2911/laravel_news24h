# Dependency Graph — AI Filmmaking OS

**Date:** 2026-07-07  
**Status:** ACTIVE — updated when new modules are added  
**Purpose:** Show what depends on what. Prevent circular deps. Guide PR review. Divide work safely.

---

## Rule: Dependency direction is ONE-WAY

Dependencies flow **DOWN** only. A lower layer NEVER imports a higher layer.

```
Provider Runtime      (highest — Phase G)
       │
  FilmOS Core         (Phase B)
       │
     AFOS             (frozen — Phase A)
       │
   PHP stdlib         (lowest)
```

If you find yourself importing upward, the dependency is wrong.

---

## Module Map

```
┌─────────────────────────────────────────────────────────────────────┐
│  PROVIDER RUNTIME  (Phase G)                                        │
│  CapabilitySpec · CapabilityResolver · CapabilityCatalog            │
│  ProviderAdapter (Kling · Veo · Runway)                             │
│                                                                     │
│  Imports: CapabilitySpec only. NO FilmOS. NO AFOS internals.       │
└─────────────────────────────────────────────────────────────────────┘
                              │ (uses CapabilitySpec)
┌─────────────────────────────────────────────────────────────────────┐
│  GRAPH ASSEMBLER  (bridge — legacy layer)                           │
│  GraphAssembler · ShotGoalIRAdapter · PipelineContext               │
│                                                                     │
│  Imports: FrozenProductionBible, PlanningContextBuilder,            │
│           AFOS\Ir\*, AFOS\Creative\*, AfosPassManager               │
│  Does NOT import: AFOS\Passes\* internals                           │
└─────────────────────────────────────────────────────────────────────┘
         │ (reads FrozenProductionBible)    │ (calls AfosPassManager)
┌────────────────────────────┐    ┌─────────────────────────────────┐
│  FILMOS CORE  (Phase B)    │    │  AFOS COMPILER  (frozen)        │
│                            │    │                                 │
│  PlanningContextBuilder    │    │  AFOS\Passes\* (internal)       │
│       │ reads              │    │  AfosPassManager                │
│  FrozenProductionBible     │    │  KlingBackend                   │
│       │ contains           │    │  KlingPromptPlanningPass        │
│  ProductionBible           │    │                                 │
│    ├── StyleModule         │    │  PUBLIC (importable by FilmOS): │
│    ├── WorldModule         │    │  AFOS\Ir\ShotGoalIR             │
│    ├── CharacterModule     │    │  AFOS\Ir\PromptIR               │
│    └── AssetModule         │    │  AFOS\Ir\CompositionIR          │
│                            │    │  AFOS\Ir\CameraIR               │
│  ConstraintEngine          │    │  AFOS\Creative\DirectorProfile  │
│    └── reads PlanningCtx   │    │  AFOS\Creative\Intent           │
│                            │    │  AFOS\Production\ProductionState│
│  PlanningContext (agg)     │    │                                 │
│    ├── ShotContext          │    │  PRIVATE (FilmOS must NOT use): │
│    ├── VisualContext        │    │  AFOS\Passes\*                  │
│    ├── CharacterContext     │    │  AFOS\Backends\*                │
│    ├── MotionContext        │    │  AFOS\Compiler\*                │
│    └── EditingContext       │    │  AFOS\Planning\SimpleCam*       │
│                            │    └─────────────────────────────────┘
│  SceneGraph v2             │
│    ├── SceneNode            │
│    └── ShotNode             │
│                            │
│  Repositories (interfaces) │
└────────────────────────────┘
```

---

## FilmOS Internal Dependency Order

Within FilmOS Core, dependencies flow in this exact order. Build **bottom-up**.

```
Level 0 — No internal deps (build first, in parallel)
├── StyleModule         (LensBible, LightingBible, CompositionBible,
│                        MovementBible, ColorBible, FocusBible, TransitionBible)
├── WorldModule         (Location, LocationState, WorldModel)
├── CharacterModule     (CharacterDefinition, CharacterState, AppearanceAnchor)
└── AssetModule         (AssetDefinition, AssetInstance)

Level 1 — Depends on Level 0
├── ProductionBible     (contains: Style + World + Character + Asset)
└── SceneGraphV2        (references: CharacterModule, WorldModule, AssetModule)

Level 2 — Depends on Level 1
└── FrozenProductionBible   (produced by ProductionBible.lock())

Level 3 — Depends on Level 2
├── PlanningContext          (ShotContext + VisualContext + CharacterContext
│                             + MotionContext + EditingContext)
└── PlanningContextBuilder   (reads FrozenProductionBible → PlanningContext)

Level 4 — Depends on Level 3
├── ConstraintEngine         (evaluates PlanningContext)
└── GraphAssembler (wired)   (uses PlanningContextBuilder + FrozenProductionBible)
```

**Rule:** Never import a Level N module into a Level < N module. This creates circular deps.

---

## Cross-boundary import rules (enforcement)

```
✓ ALLOWED
FilmOS → AFOS\Ir\*          (ShotGoalIR, PromptIR, CameraIR, CompositionIR)
FilmOS → AFOS\Creative\*    (DirectorProfile, CinematographyProfile, Intent)
FilmOS → AFOS\Production\*  (ProductionState)
GraphAssembler → FrozenProductionBible
GraphAssembler → PlanningContextBuilder
GraphAssembler → AfosPassManager (entry point only)

✗ FORBIDDEN
FilmOS → AFOS\Passes\*      (internal stages)
FilmOS → AFOS\Backends\*    (internal backends)
FilmOS → AFOS\Compiler\*    (internal compiler)
AFOS   → FilmOS\*           (AFOS knows nothing about FilmOS)
Provider → FilmOS\*         (Provider knows CapabilitySpec only)
Provider → AFOS\*           (Provider knows nothing about AFOS)
```

---

## Circular dependency watchlist

These are the most common circular dep patterns in this type of system. Do not let them form.

| Pattern | Why dangerous |
|---------|---------------|
| `ConstraintEngine → ProductionBible → ConstraintEngine` | Constraint reads Bible; Bible must not call Constraint |
| `PlanningContext → CharacterModule → PlanningContext` | Context aggregates modules; modules must not reference Context |
| `GraphAssembler → PlanningContextBuilder → GraphAssembler` | Builder reads Bible; Assembler uses Builder; never the other way |
| `FrozenProductionBible → ProductionBible` | Frozen is produced by ProductionBible; must never reference parent |
| `Repository → Domain object → Repository` | Domain objects are pure; they never call repos |

---

## Detect violations (run before every PR merge)

```bash
# FilmOS must not import AFOS internals
grep -rn "use App\\Services\\AI\\AFOS\\Passes\\"   app/Services/AI/FilmOS/ --include="*.php"
grep -rn "use App\\Services\\AI\\AFOS\\Backends\\" app/Services/AI/FilmOS/ --include="*.php"
grep -rn "use App\\Services\\AI\\AFOS\\Compiler\\" app/Services/AI/FilmOS/ --include="*.php"

# AFOS must not import FilmOS
grep -rn "use App\\Services\\AI\\FilmOS\\" app/Services/AI/AFOS/ --include="*.php"

# Provider must not import FilmOS or AFOS
grep -rn "use App\\Services\\AI\\FilmOS\\" app/Services/AI/Providers/ --include="*.php"
grep -rn "use App\\Services\\AI\\AFOS\\"   app/Services/AI/Providers/ --include="*.php"

# Domain objects must not import repositories
grep -rn "Repository" app/Services/AI/FilmOS/Core/ --include="*.php"
```

Add these to `.git/hooks/pre-push` or CI.
