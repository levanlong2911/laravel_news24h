# ADR-001: Freeze AFOS Compiler Core v1

**Status:** Accepted  
**Date:** 2026-07-06  
**Deciders:** Project Lead

---

## Context

AFOS (`app/Services/AI/AFOS/`) is a typed, staged compiler with 5 IR tiers:

```
ShotGoalIR → CompositionIR → CameraIR → PromptIR → PromptArtifacts
```

It has 101 PHP files, 9 compiler stages, typed diagnostics, backend abstraction,
benchmark/QA, temporal graph, and optimizer. Estimated completion: 90–95%.

The project currently has **two parallel compilation paths**:

1. **AFOS** — `AfosPassManager` (typed IR pipeline, already imported in `GraphAssembler`)
2. **Legacy** — `PromptCompiler` + `PromptAST` (untyped DSL → prompt string)

Continuing to add features to both paths creates maintenance burden.
Continuing to add features to AFOS itself risks bloating the compiler with
business logic that belongs in an orchestration layer.

---

## Decision

### 1. Freeze AFOS v1 — no new business logic

AFOS v1 is declared **stable**. Permitted changes only:

| Allowed | Not Allowed |
|---------|-------------|
| Bug fixes | New IR fields for World/Character data |
| Performance optimization | New compiler stages for story logic |
| New backends (Veo, Runway, Sora) | Character state tracking |
| New validators (focal, motion) | Scene continuity logic |
| Diagnostic code additions | Asset memory / reference images |

**Rationale:** The compiler should compile. It should not know that a "woman" exists,
that the "hotel room" appeared in Scene 1, or that the lighting must match Scene 3.
Those are orchestration concerns.

### 2. Deprecate PromptCompiler/PromptAST as primary path

`app/Services/AI/PromptCompiler/` and `app/Services/AI/PromptAST/` are the legacy
untyped path. They remain in place for backward compatibility but:

- No new features added to PromptCompiler
- All new shots must flow through AFOS
- `GraphAssembler` migration target: replace `Compiler` calls with `AfosPassManager`

Migration is not immediate — it happens as `FilmOS` layer is built (Phase 2+).

### 3. Define the contract boundary

FilmOS (upper orchestration layer) communicates with AFOS **only** through these
public contracts:

**Allowed imports from FilmOS → AFOS:**
```php
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ir\PromptIRSnapshot;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineInputs;
use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Types\*;   // enums only
```

**Forbidden imports from FilmOS → AFOS:**
```php
// These are internal compiler concerns
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;     // ❌
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;     // ❌
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;         // ❌
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag; // ❌ (read via Snapshot only)
```

**AFOS must never import from FilmOS:**
```php
use App\Services\AI\FilmOS\*;  // ❌ — AFOS knows nothing about FilmOS
```

The contract is: `ShotGoalIR in → PromptIRSnapshot out`.

```
[FilmOS: PlanningContext]
        │
        │  produces
        ▼
[AFOS: ShotGoalIR]  ──→  AfosPassManager  ──→  [AFOS: PromptIRSnapshot]
                                                        │
                                                        │  consumed by
                                                        ▼
                                              [FilmOS: EditingEngine / Producer]
```

---

## Consequences

### Positive
- AFOS can be versioned independently (v1.x = stable)
- FilmOS can evolve fast without touching compiler internals
- New backends (Veo, Runway) added purely in `AFOS/Backends/` — zero FilmOS changes
- Clear responsibility: "AFOS compiles one shot. FilmOS decides what shots to compile."

### Negative
- `ShotGoalIR` must carry enough information for AFOS to compile correctly
  (emotion, energy, goal, narrative — but NOT world/character data)
- Migration of `GraphAssembler` from `PromptCompiler` to `AfosPassManager` is
  non-trivial work, deferred to Phase 3 (SceneGraph v2)

### Neutral
- `PlanningContext` (ADR-002) will be the buffer that translates World+Character+Scene
  data into `ShotGoalIR` without leaking world concepts into AFOS

---

## Alternatives Considered

**Alternative A: Keep adding to AFOS**  
Rejected. Within 6 months AFOS would contain Character state, World lighting,
ContinuityEngine — all unrelated to prompt compilation. Maintenance complexity
grows O(n²) with feature count.

**Alternative B: Merge PromptCompiler into AFOS**  
Rejected now. Possible after FilmOS layer is stable. Migration deferred.

**Alternative C: Two separate packages (composer)**  
Considered. Not needed yet. Can be done if projects diverge.

---

## References

- LLVM/Clang split as analogous architecture pattern
- `app/Services/AI/AFOS/Passes/AfosPassManager.php` — current entry point
- `app/Services/AI/SceneGraph/GraphAssembler.php` — current integration site
- ADR-002: FilmOS Unified Model
