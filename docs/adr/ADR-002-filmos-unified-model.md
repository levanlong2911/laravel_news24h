# ADR-002: FilmOS Unified Model

**Status:** Proposed — Amended 2026-07-06  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-001  
**Amended by:** ADR-003 (extended engines), Amendment A (RenderContext), Amendment B (Module Pattern), Amendment C (PlanningContext decomposition), Amendment D (extended ConstraintEngine)

---

## Context

The current `app/Services/AI/` layer has functional planners:

- `StoryPlanner/` — Article → StoryDTO (Claude)
- `SceneShotPlanner/` — StoryDTO → SceneDTO[] (Claude + rules)
- `ScenePlanner/` — enriches shot DSL with 16 plan types (rules)
- `SceneGraph/` — assembles + validates final SceneGraph JSON
- `ContinuityEngine` — per-scene visual anchor injection (basic)

What is missing is a **unified data model** that answers:

- Who is in this shot? (same woman from Scene 1)
- What room is this? (same hotel room, same lighting)
- Was the kettle open or closed in the previous shot?
- Can a character be in the lobby and the pool at the same time?
- What is the director's lens philosophy for this entire production?

Without this model, each shot is compiled in isolation. The system can produce
technically correct prompts that are narratively inconsistent.

---

## Decision

Build a new namespace `app/Services/AI/FilmOS/` with five interconnected subsystems.
These are designed together because their contracts are coupled:

```
ProductionBible ──────────────────────────────────────┐
    ├── StyleBible                                     │
    ├── WorldRegistry (World[])                        │ read-only
    ├── CharacterRegistry (CharacterDefinition[])      │ by SceneGraph
    └── AssetRegistry (AssetDefinition[])              │
                                                       ▼
                                                  SceneGraph v2
                                               (references, not contains)
                                                       │
                                                       ▼
                                              ConstraintEngine
                                               (validates graph)
                                                       │
                                                       ▼
                                              PlanningContext
                                               (per-shot bridge)
                                                       │
                                                       ▼
                                               ShotGoalIR (AFOS)
```

---

## Subsystem 1: ProductionBible

Root container for an entire production. Immutable after `lock()`.
One `ProductionBible` per `VideoProject`.

```php
namespace App\Services\AI\FilmOS\Bible;

final class ProductionBible
{
    private function __construct(
        public readonly string          $productionId,
        public readonly string          $title,
        public readonly string          $version,       // semver: "1.0.0"
        public readonly StyleBible      $style,
        public readonly WorldRegistry   $worlds,
        public readonly CharacterRegistry $characters,
        public readonly AssetRegistry   $assets,
    ) {}

    public static function build(ProductionBibleBuilder $builder): self { ... }
    public function lock(): FrozenProductionBible { ... }
    public function world(string $id): World { ... }
    public function character(string $id): CharacterDefinition { ... }
    public function asset(string $id): AssetDefinition { ... }
}
```

**Why lock():** Once a production starts generating shots, the Bible must not change.
A `FrozenProductionBible` is passed to `ConstraintEngine` and `SceneGraph`.

---

## Subsystem 2: StyleBible

One per production. Encodes the director's vision across all shots.
Changing `StyleBible` is equivalent to "re-casting the director."

```php
namespace App\Services\AI\FilmOS\Bible;

final class StyleBible
{
    public readonly DirectorProfile        $director;     // from AFOS\Creative
    public readonly CinematographyProfile  $dp;           // from AFOS\Creative
    public readonly LensVocabulary         $lensVocab;    // allowed focal lengths
    public readonly ColorGradeProfile      $colorGrade;   // locked palette + LUT name
    public readonly EditingStyle           $editing;      // cut frequency, transition prefs
    public readonly MusicStyle             $music;        // tempo, genre, sync points
    public readonly VoiceStyle             $voice;        // tone, language, pace
}

final class ColorGradeProfile
{
    public readonly string      $name;       // "Fincher Cool", "Villeneuve Warm"
    public readonly string      $lutId;      // reference LUT for image generation
    public readonly ColorRange  $shadows;
    public readonly ColorRange  $midtones;
    public readonly ColorRange  $highlights;
    public readonly float       $saturation; // 0.0–2.0
    public readonly float       $contrast;
}

final class EditingStyle
{
    public readonly CutFrequency $cutFrequency;
    public readonly TransitionPreference $transitions; // CUT, DISSOLVE, MATCH_CUT
    public readonly bool $preferMatchCuts;
    public readonly bool $preferJCuts;
}
```

**Key constraint:** `StyleBible::$director` and `StyleBible::$dp` reuse the existing
`AFOS\Creative\DirectorProfile` and `AFOS\Creative\CinematographyProfile` types.
AFOS types are allowed in FilmOS; FilmOS types are never allowed in AFOS.

---

## Subsystem 3: WorldModel

### 3a. World

Physical + temporal context for a location. Shots reference a `worldId`, never
embed world properties directly.

```php
namespace App\Services\AI\FilmOS\World;

final class World
{
    public readonly string           $id;           // "hotel_room_floor_7"
    public readonly string           $name;
    public readonly ArchitectureStyle $architecture; // MODERN, CLASSICAL, BRUTALIST...
    public readonly TimeOfDay        $timeOfDay;    // GOLDEN_HOUR, NOON, MIDNIGHT...
    public readonly LightingMood     $lighting;     // SOFT_NATURAL, HARSH_NOON, NEON...
    public readonly WeatherState     $weather;      // CLEAR, OVERCAST, RAIN...
    public readonly ColorPalette     $palette;      // locked across all shots in this world
    public readonly CameraPhilosophy $cameraStyle;  // from AFOS\Types
    public readonly ?string          $referenceImageId; // → AssetMemory
}
```

### 3b. CharacterDefinition vs CharacterState

**CharacterDefinition** — immutable visual identity. Never changes during production.

```php
namespace App\Services\AI\FilmOS\Character;

final class CharacterDefinition
{
    public readonly string       $id;           // "woman_protagonist"
    public readonly string       $name;
    public readonly Gender       $gender;
    public readonly AgeRange     $age;          // "25-30"
    public readonly HairDescriptor $hair;       // "long dark wavy hair"
    public readonly FaceDescriptor $face;       // "sharp jawline, brown eyes, high cheekbones"
    public readonly BodyDescriptor $body;       // "slim, 165cm"
    public readonly WalkingStyle $gait;         // CONFIDENT, TIMID, HURRIED...
    public readonly ?string      $referenceImageId;
}
```

**CharacterState** — dynamic, per-shot snapshot. Mutates across scenes.

```php
final class CharacterState
{
    public readonly string    $characterId;
    public readonly string    $shotId;          // which shot this state belongs to
    public readonly Emotion   $emotion;         // NEUTRAL, DISGUST, FEAR...
    public readonly PoseCode  $pose;            // STANDING, WALKING, LOOKING_LEFT...
    public readonly WardrobeState $wardrobe;    // "navy blazer, white blouse"
    public readonly DirtLevel $dirt;            // CLEAN, DUSTY, WET, MUDDY
    public readonly float     $injuryLevel;     // 0.0 = healthy, 1.0 = incapacitated
    public readonly string    $locationId;      // which asset/world they're in
}
```

**Why separate:** Definition answers "who is she always."
State answers "who is she right now." Mixing them means changing wardrobe
requires re-declaring hair color — a common source of inconsistency bugs.

### 3c. AssetDefinition vs AssetInstance (ECS-inspired)

**AssetDefinition** — the template. Declared once in `ProductionBible`.

```php
namespace App\Services\AI\FilmOS\Asset;

final class AssetDefinition
{
    public readonly string    $id;           // "electric_kettle_silver"
    public readonly AssetType $type;         // PROP, FURNITURE, VEHICLE, ARCHITECTURE...
    public readonly string    $descriptor;   // "modern silver electric kettle, hotel style"
    public readonly array     $tags;         // ["kitchen", "hotel", "appliance"]
    public readonly ?string   $referenceImageId;
}
```

**AssetInstance** — one placement of a definition in a scene.

```php
final class AssetInstance
{
    public readonly string          $instanceId;    // "kettle_scene3_shot1"
    public readonly string          $definitionId;  // → AssetDefinition::$id
    public readonly string          $worldId;       // where it is
    public readonly AssetState      $state;         // INTACT, OPEN, CLOSED, BROKEN, EMPTY
    public readonly string          $placement;     // "on the desk near the window"
}
```

**Example:** `electric_kettle_silver` (definition) can appear as:
- `kettle_scene3_shot2` (closed, on desk)
- `kettle_scene3_shot4` (open, revealed with underwear inside)

The Constraint Engine tracks state transitions between instances.

---

## Subsystem 4: SceneGraph v2

The heart of FilmOS. A Scene **references** World/Characters/Assets — it does not contain them.

```php
namespace App\Services\AI\FilmOS\Scene;

final class SceneNode
{
    public readonly string   $sceneId;
    public readonly int      $sceneNumber;
    public readonly string   $title;
    public readonly string   $worldId;                    // → World
    public readonly array    $characterIds;               // → CharacterDefinition[]
    public readonly array    $assetInstanceIds;           // → AssetInstance[]
    public readonly NarrativeFunction $narrativeFunction; // SETUP, BUILD, CLIMAX, RESOLVE
    public readonly Emotion  $emotionArc;
    public readonly float    $duration;

    /** @var ShotNode[] */
    public readonly array    $shots;
}

final class ShotNode
{
    public readonly string  $shotId;
    public readonly string  $sceneId;
    public readonly int     $shotOrder;

    // References — never embed world/character data here
    public readonly string  $worldId;
    public readonly array   $characterStateIds;   // CharacterState IDs for this shot
    public readonly array   $assetInstanceIds;

    // Shot-level intent (feeds directly into ShotGoalIR)
    public readonly GoalType          $goalType;
    public readonly Emotion           $emotion;
    public readonly float             $energy;      // 0.0–1.0
    public readonly NarrativeFunction $narrativeFunction;
    public readonly float             $duration;
    public readonly string            $goalTarget;  // "reveal underwear in kettle"
}
```

**Relationship to existing `SceneDTO`:**

`SceneNode` replaces `SceneDTO` as the typed representation inside `FilmOS`.
`SceneDTO` (the untyped array DTO) is kept for backward compatibility at the
API boundary only. `SceneGraphCompiler` will bridge `SceneNode[]` → SceneGraph JSON.

---

## Subsystem 5: ConstraintEngine

**The Constraint Engine prevents impossible states from being generated.**  
This is different from `ContinuityEngine` (which checks after-the-fact).

```php
namespace App\Services\AI\FilmOS\Constraint;

interface Constraint
{
    public function name(): string;
    public function validate(SceneGraph $graph, FrozenProductionBible $bible): ConstraintResult;
}
```

### Built-in constraints:

**SpatialConstraint** — a character cannot be in two places simultaneously.
```
Scene 2, Shot 1: Woman is in Hotel Lobby
Scene 2, Shot 3: Woman is in Pool (same timestamp range)
→ ERROR: SPATIAL_CONFLICT — woman_protagonist cannot be in hotel_lobby and pool_01 at the same time
```

**TemporalConstraint** — time of day must not jump impossibly.
```
Scene 1: GOLDEN_HOUR (18:00–19:00)
Scene 2: MIDNIGHT    (00:00–01:00)
Scene 3: GOLDEN_HOUR (18:00–19:00)   ← impossible without time jump explanation
→ WARNING: TEMPORAL_JUMP — no transition beat accounts for 6h reversal between Scene 2 and Scene 3
```

**StateTransitionConstraint** — asset state changes require a causal action.
```
Shot 3: kettle_hotel_01 state=CLOSED
Shot 5: kettle_hotel_01 state=OPEN
→ ERROR: MISSING_STATE_TRANSITION — no shot shows kettle being opened between shot 3 and shot 5
```

**CharacterContinuityConstraint** — wardrobe must not change without cause.
```
Scene 1: woman wears navy blazer
Scene 3: woman wears red dress  (no scene with wardrobe change between)
→ WARNING: WARDROBE_DISCONTINUITY — no transition shot accounts for wardrobe change
```

```php
final class ConstraintEngine
{
    /** @var Constraint[] */
    private array $constraints;

    public function validate(
        SceneGraph $graph,
        FrozenProductionBible $bible,
    ): ConstraintReport {
        $errors   = [];
        $warnings = [];

        foreach ($this->constraints as $constraint) {
            $result = $constraint->validate($graph, $bible);
            foreach ($result->errors()   as $e) { $errors[]   = $e; }
            foreach ($result->warnings() as $w) { $warnings[] = $w; }
        }

        return new ConstraintReport($errors, $warnings);
    }

    public function defaults(): self { ... }
}

final class ConstraintReport
{
    public function hasBlockers(): bool { ... }   // any ERROR-level constraints
    public function errors(): array    { ... }
    public function warnings(): array  { ... }
    public function format(): string   { ... }
}
```

**Constraint vs Continuity — the distinction:**

| | ConstraintEngine | ContinuityEngine (current) |
|---|---|---|
| When | Before shot compilation | During shot compilation |
| What | Logical impossibilities | Visual anchoring |
| Action | Block / warn | Inject anchor string |
| Example | Woman can't teleport | "Same woman from Shot 1" |

---

## Subsystem 6: PlanningContext (bridge to AFOS)

`PlanningContext` is the **only** object that crosses from FilmOS into AFOS territory.
It resolves a `ShotNode` + `FrozenProductionBible` into a `ShotGoalIR`.

```php
namespace App\Services\AI\FilmOS\Planning;

/**
 * Per-shot context assembled by FilmOS before calling AFOS.
 * Contains everything AFOS needs to compile one shot — and nothing more.
 *
 * ShotGoalIR is built FROM PlanningContext.
 * ShotGoalIR does NOT know about World, Character, or Asset.
 */
final class PlanningContext
{
    public readonly ShotNode              $shot;
    public readonly World                 $world;
    public readonly CharacterDefinition[] $characters;
    public readonly CharacterState[]      $characterStates;
    public readonly AssetInstance[]       $assets;
    public readonly StyleBible            $style;
    public readonly DirectorNotes         $directorNotes;
    public readonly ContinuityLocks       $continuityLocks; // from ContinuityEngine
    public readonly ConstraintReport      $constraints;     // from ConstraintEngine
}

interface ShotPlanner
{
    /**
     * FilmOS → AFOS boundary.
     * Translates PlanningContext into the typed ShotGoalIR that AFOS understands.
     */
    public function plan(PlanningContext $context): ShotGoalIR;
}

final class DefaultShotPlanner implements ShotPlanner
{
    public function plan(PlanningContext $context): ShotGoalIR
    {
        return ShotGoalIR::fromArray([
            'duration'          => $context->shot->duration,
            'goalType'          => $context->shot->goalType->value,
            'goalTarget'        => $context->shot->goalTarget,
            'emotion'           => $context->shot->emotion->value,
            'energy'            => $context->shot->energy,
            'narrativeFunction' => $context->shot->narrativeFunction->value,
        ]);
    }
}
```

---

## Amendment A: RenderContext (parallel path to backend)

> **Amendment date:** 2026-07-06
> **Reason:** Original ADR stated "World + Character data is NOT passed to AFOS."
> This is architecturally correct but practically incomplete: backend serializers
> (KlingBackend, VeoBackend) need visual descriptors to produce coherent prompts.
> `ShotGoalIR` alone cannot encode "same woman, navy blazer, brown eyes" needed
> for character consistency across shots.

### Solution: RenderContext passes in parallel, not through AFOS

```
PlanningContext
        │
        ├── ShotGoalIR ──────────► AfosPassManager ──► PromptIR
        │                                                   │
        └── RenderContext                                   │
             ├── characterDescriptors[]                     │
             ├── worldDescriptor                            ▼
             ├── assetDescriptors[]         BackendEmitter.emit(PromptIR, RenderContext)
             ├── visualMemoryRefs[]                         │
             └── styleLocks                                 ▼
                                              Kling/Veo/Runway final prompt
```

### RenderContext contract

`RenderContext` lives in `app/Services/AI/Contracts/` (shared, no circular dependency).

```php
namespace App\Services\AI\Contracts;

/**
 * Visual context passed alongside PromptIR to backend serializers.
 * AFOS compiler never reads this — it flows directly to BackendEmitter.
 *
 * Pure DTO: no methods, no logic. Backends read what they need.
 */
final class RenderContext
{
    /** @param CharacterRenderDescriptor[] $characters */
    public function __construct(
        public readonly array             $characters,    // one per character in shot
        public readonly WorldDescriptor   $world,
        public readonly array             $assets,        // AssetRenderDescriptor[]
        public readonly array             $memoryRefs,    // VisualMemoryRef[] (Phase E)
        public readonly StyleLocks        $styleLocks,    // from StyleBible
    ) {}

    public static function empty(): self { ... }
    public function toArray(): array { ... }
}

final class CharacterRenderDescriptor
{
    public function __construct(
        public readonly string  $characterId,
        public readonly string  $visualSummary,    // "slim woman, 25-30, long dark wavy hair,
                                                   //  brown eyes, navy blazer, white blouse"
        public readonly string  $currentEmotion,   // "shocked, mouth slightly open"
        public readonly string  $currentPose,      // "standing, holding kettle"
        public readonly ?string $referenceImageId,
    ) {}
}

final class WorldDescriptor
{
    public function __construct(
        public readonly string $worldId,
        public readonly string $visualSummary,  // "modern hotel room, floor-to-ceiling windows,
                                                //  golden afternoon light, navy/white palette"
        public readonly string $lightingNote,   // "soft window backlight, warm tones"
        public readonly ?string $referenceImageId,
    ) {}
}

final class StyleLocks
{
    public function __construct(
        public readonly string  $colorGrade,    // "Fincher Cool — cyan shadows, desaturated"
        public readonly string  $lensNote,      // "40mm equivalent, shallow DOF"
        public readonly string  $lightingStyle, // "practical sources, negative fill"
    ) {}
}
```

### BackendInput amendment

`BackendInput` (AFOS) gets one optional field:

```php
// AFOS/Ir/BackendInput.php — add optional field (backward compatible)
final class BackendInput implements StageInput
{
    public function __construct(
        public readonly PromptIR      $promptIr,
        public readonly string        $backendId,
        public readonly ?RenderContext $renderContext = null,   // ← added
    ) {}
}
```

`RenderContext` is in `App\Services\AI\Contracts\` — not in `FilmOS\` — so AFOS importing
it does not violate ADR-001's boundary rule.

### KlingBackend with RenderContext

```php
// AFOS/Backends/KlingBackend.php — render context enrichment
public function serialize(PromptIR $ir, ?RenderContext $ctx = null): string
{
    $base = $this->buildBasePrompt($ir);

    if ($ctx === null) {
        return $base;  // backward compatible: works without context
    }

    // Inject character consistency anchor
    $charBlock = implode(', ', array_map(
        fn ($c) => $c->visualSummary,
        $ctx->characters
    ));

    // Inject style lock
    $styleNote = $ctx->styleLocks->colorGrade . '. ' . $ctx->styleLocks->lensNote;

    return $base . " [SUBJECT: {$charBlock}] [STYLE: {$styleNote}]";
}
```

### Summary of the two paths

| | ShotGoalIR path | RenderContext path |
|---|---|---|
| Carries | Shot intent (goal, emotion, energy) | Visual identity (who, what, how) |
| Goes through | AFOS compiler pipeline | Direct to BackendEmitter |
| Known by AFOS | Yes — compiled to PromptIR | No — AFOS never reads world/character |
| Known by backend | Via PromptIR | Directly via BackendInput |
| Changes when | Shot intent changes | Character/world/style changes |

This preserves the clean compiler boundary while ensuring backend serializers
produce visually consistent, identity-locked prompts.

---

## Complete Stack

```
Article
    │
    ▼
StoryPlanner (Claude)
    │  StoryDTO
    ▼
SceneShotPlanner (Claude + rules)
    │  SceneDTO[] → [migration target: SceneNode[]]
    ▼
ProductionBible.lock()
    │  FrozenProductionBible
    ▼
ConstraintEngine.validate()
    │  ConstraintReport (block if hasBlockers())
    ▼
SceneGraph v2  (SceneNode[] with ShotNode[])
    │
    ├── for each ShotNode
    │       │
    │       ▼
    │   PlanningContext (assembled by FilmOS)
    │       │
    │       ▼
    │   ShotPlanner.plan() → ShotGoalIR
    │       │
    │       ▼
    │   AfosPassManager.compileWithSnapshot()
    │       │
    │       ▼
    │   PromptIRSnapshot
    │
    ▼
EditingEngine (Phase 7)
    │  EditDecisionList
    ▼
Producer (Phase 10)
    │
    ▼
Video + Voice + Subtitle + Publish
```

---

## Directory Structure

```
app/Services/AI/FilmOS/
├── Bible/
│   ├── ProductionBible.php
│   ├── ProductionBibleBuilder.php
│   ├── FrozenProductionBible.php
│   ├── StyleBible.php
│   ├── ColorGradeProfile.php
│   ├── EditingStyle.php
│   ├── MusicStyle.php
│   └── VoiceStyle.php
├── World/
│   ├── World.php
│   ├── WorldRegistry.php
│   ├── WorldBuilder.php
│   └── Enums/
│       ├── TimeOfDay.php
│       ├── LightingMood.php
│       ├── WeatherState.php
│       └── ArchitectureStyle.php
├── Character/
│   ├── CharacterDefinition.php
│   ├── CharacterState.php
│   ├── CharacterRegistry.php
│   └── Descriptors/
│       ├── HairDescriptor.php
│       ├── FaceDescriptor.php
│       └── BodyDescriptor.php
├── Asset/
│   ├── AssetDefinition.php
│   ├── AssetInstance.php
│   ├── AssetRegistry.php
│   └── Enums/
│       ├── AssetType.php
│       └── AssetState.php
├── Scene/
│   ├── SceneNode.php
│   ├── ShotNode.php
│   └── SceneGraph.php          (FilmOS-level, distinct from legacy SceneGraph/)
├── Constraint/
│   ├── Constraint.php          (interface)
│   ├── ConstraintEngine.php
│   ├── ConstraintReport.php
│   ├── ConstraintResult.php
│   └── Constraints/
│       ├── SpatialConstraint.php
│       ├── TemporalConstraint.php
│       ├── StateTransitionConstraint.php
│       └── CharacterContinuityConstraint.php
└── Planning/
    ├── PlanningContext.php
    ├── PlanningContextBuilder.php
    ├── ShotPlanner.php         (interface)
    └── DefaultShotPlanner.php
```

---

## Phased Implementation Order

| Phase | Subsystem | Prerequisite |
|-------|-----------|-------------|
| B1 | `Bible/` — ProductionBible + StyleBible skeleton | ADR-001 accepted |
| B2 | `World/` — World + WorldRegistry | B1 |
| B3 | `Character/` — CharacterDefinition + CharacterState | B1 |
| B4 | `Asset/` — AssetDefinition + AssetInstance | B1 |
| B5 | `Constraint/` — ConstraintEngine + 4 built-in constraints | B2+B3+B4 |
| B6 | `Scene/` — SceneNode + ShotNode | B2+B3+B4 |
| B7 | `Planning/` — PlanningContext + DefaultShotPlanner | B5+B6 |
| B8 | Wire into GraphAssembler (replace SceneDTO with SceneNode path) | B7 |

**Do not start B8 until B1–B7 contracts are stable.** B8 touches production code.

---

## Consequences

### Positive
- `ShotGoalIR` stays small — one shot's intent, no world/character coupling
- Adding a new character takes one `CharacterDefinition` in `ProductionBible` — every shot picks it up automatically
- Changing the director's style takes one `StyleBible` update
- `ConstraintEngine` prevents impossible scenes before any AI call is made (saves cost)
- `CharacterDefinition` / `CharacterState` split makes wardrobe, injury, emotion changes explicit and trackable

### Negative
- `SceneDTO` → `SceneNode` migration in `SceneShotPlanner` output requires parallel support period
- `ContinuityEngine` (current, per-scene anchor) must be replaced or wrapped by new `CharacterContinuityConstraint`
- `ProductionBible` must be built before any shot can be compiled — new pipeline initialization step

### Open Questions — RESOLVED by ADR-005
1. `SceneNode` vs `SceneDTO` — coexist at API boundary, `SceneNode` is internal canonical form.
2. `CharacterState` persistence — see ADR-005 (DB table `production_character_states`).
3. `AssetInstance` state — input to `ConstraintEngine`, output mutated only if constraint passes.

---

## Amendment B: ProductionBible Module Pattern

> **Amendment date:** 2026-07-06
> **Problem:** `ProductionBible` as originally designed is a God Object. It contains
> `StyleBible`, `WorldRegistry`, `CharacterRegistry`, `AssetRegistry` directly.
> Future phases will add `VisualMemoryModule`, `ActingModule`, `MotionModule`,
> `EditingModule` — causing `ProductionBible` to absorb all domain logic.

### Solution: ProductionBible = root aggregate only

`ProductionBible` owns IDs and coordinates modules. It contains **no logic**.
Each module is self-contained with its own query interface.

```php
namespace App\Services\AI\FilmOS\Bible;

/**
 * Root aggregate for a production. Pure coordinator — no business logic.
 * All domain operations go through the relevant Module.
 */
final class ProductionBible
{
    private function __construct(
        public readonly string          $productionId,
        public readonly string          $title,
        public readonly string          $version,
        public readonly WorldModule     $world,
        public readonly CharacterModule $character,
        public readonly AssetModule     $asset,
        public readonly StyleModule     $style,
        // Future modules added here without modifying this class's logic:
        // public readonly VisualMemoryModule $memory,
        // public readonly ActingModule $acting,
        // public readonly EditingModule $editing,
    ) {}

    public static function build(ProductionBibleBuilder $b): self { ... }
    public function lock(): FrozenProductionBible { ... }
}
```

### Each module is focused

```php
final class WorldModule
{
    public function get(string $worldId): World { ... }
    public function all(): array { ... }
    public function has(string $worldId): bool { ... }
    public function register(World $world): self { ... }  // returns new instance (immutable)
}

final class CharacterModule
{
    public function definition(string $charId): CharacterDefinition { ... }
    public function state(string $charId, string $shotId): CharacterState { ... }
    public function allDefinitions(): array { ... }
    public function withState(CharacterState $state): self { ... }
}

final class AssetModule
{
    public function definition(string $assetId): AssetDefinition { ... }
    public function instance(string $instanceId): AssetInstance { ... }
    public function instancesFor(string $sceneId): array { ... }
    public function withInstance(AssetInstance $instance): self { ... }
}

final class StyleModule
{
    public function lensBible(): LensBible { ... }
    public function lightingBible(): LightingBible { ... }
    public function compositionBible(): CompositionBible { ... }
    public function movementBible(): MovementBible { ... }
    public function colorBible(): ColorBible { ... }
    public function focusBible(): FocusBible { ... }
    public function transitionBible(): TransitionBible { ... }
    public function toStyleBible(): StyleBible { ... }
}
```

### Updated Directory Structure (Bible/)

```
FilmOS/Bible/
├── ProductionBible.php          ← root aggregate only
├── ProductionBibleBuilder.php
├── FrozenProductionBible.php
├── Modules/
│   ├── WorldModule.php
│   ├── CharacterModule.php
│   ├── AssetModule.php
│   └── StyleModule.php          ← replaces StyleBible at root
└── Style/
    ├── StyleBible.php            ← value object assembled by StyleModule
    ├── LensBible.php
    ├── LightingBible.php
    ├── CompositionBible.php
    ├── MovementBible.php
    ├── ColorBible.php
    ├── FocusBible.php
    └── TransitionBible.php
```

**Rule:** Any new capability (VisualMemory, Acting, Motion) adds a new `*Module` class
and one line in `ProductionBible` constructor. It never changes existing module code.

---

## Amendment C: PlanningContext Decomposition

> **Amendment date:** 2026-07-06
> **Problem:** `PlanningContext` as originally designed accumulates everything:
> shot, world, characters, states, assets, style, constraints, director notes,
> continuity locks. Each future phase adds more fields. It becomes an object
> with thousands of lines.

### Solution: PlanningContext = aggregate of focused sub-contexts

```php
namespace App\Services\AI\FilmOS\Planning;

/**
 * Per-shot context. Aggregate only — no logic.
 * Each sub-context is independently built and testable.
 */
final class PlanningContext
{
    public function __construct(
        public readonly ShotContext       $shot,        // what the shot intends
        public readonly VisualContext     $visual,      // world + lighting + memory
        public readonly CharacterContext  $character,   // who is in the shot
        public readonly MotionContext     $motion,      // how the camera moves
        public readonly EditingContext    $editing,     // how this shot cuts
        public readonly ConstraintReport  $constraints, // pre-validated (no errors)
    ) {}
}
```

### ShotContext — shot intent only

```php
final class ShotContext
{
    public readonly string           $shotId;
    public readonly string           $sceneId;
    public readonly int              $shotOrder;
    public readonly GoalType         $goalType;
    public readonly Emotion          $emotion;
    public readonly float            $energy;
    public readonly NarrativeFunction $narrativeFunction;
    public readonly float            $duration;
    public readonly string           $goalTarget;
}
```

### VisualContext — world + style + memory

```php
final class VisualContext
{
    public readonly World           $world;
    public readonly StyleLocks      $styleLocks;       // from StyleModule
    public readonly RetrievedMemory $memorySnapshot;   // from VisualMemoryStore (Phase E)
    public readonly WorldDescriptor $worldDescriptor;  // → RenderContext
}
```

### CharacterContext — characters + states + acting

```php
final class CharacterContext
{
    /** @var CharacterDefinition[] — who is in the shot */
    public readonly array $definitions;

    /** @var CharacterState[] — their current state */
    public readonly array $states;

    /** @var ActingBehavior[] — planned behavior per character (Phase D) */
    public readonly array $behaviors;

    /** @var CharacterRenderDescriptor[] — → RenderContext */
    public readonly array $renderDescriptors;
}
```

### MotionContext — camera grammar + continuity

```php
final class MotionContext
{
    public readonly DomainMotionProfile $motionProfile;   // from MotionLibrary (Phase D)
    public readonly ContinuityLocks     $continuityLocks;
    public readonly ?string             $prevShotId;       // for match cut analysis
    public readonly ?CameraIR           $prevCameraIR;     // for lens continuity check
}
```

### EditingContext — rhythm + transition

```php
final class EditingContext
{
    public readonly TransitionType  $transitionIn;    // what cut brings this shot in
    public readonly TransitionType  $transitionOut;   // what cut leads out
    public readonly ?float          $beatSyncMs;      // music beat to align to (Phase F)
    public readonly float           $suggestedDuration; // from RhythmPlanner
}
```

### PlanningContextBuilder

```php
final class PlanningContextBuilder
{
    public function forShot(
        ShotNode              $shot,
        FrozenProductionBible $bible,
        ConstraintReport      $constraints,
    ): PlanningContext
    {
        $world    = $bible->world->get($shot->worldId);
        $chars    = array_map(fn ($id) => $bible->character->definition($id), $shot->characterIds);
        $states   = array_map(fn ($id) => $bible->character->state($id, $shot->shotId), $shot->characterIds);
        $profile  = $this->motionLibrary->profileFor($world->domain, $bible->style->toStyleBible());

        return new PlanningContext(
            shot:        ShotContext::fromNode($shot),
            visual:      new VisualContext($world, $bible->style->toStyleLocks(), RetrievedMemory::empty()),
            character:   new CharacterContext($chars, $states, [], []),
            motion:      new MotionContext($profile, ContinuityLocks::empty(), null, null),
            editing:     EditingContext::defaults(),
            constraints: $constraints,
        );
    }
}
```

---

## Amendment D: Extended ConstraintEngine

> **Amendment date:** 2026-07-06
> **Problem:** Original 4 constraints (Spatial, Temporal, StateTransition,
> CharacterContinuity) cover physical consistency but miss semantic,
> camera, lighting, and emotion-body mismatches — common AI generation errors.

### 4 new constraint types

#### SemanticConstraint — object/environment coherence

AI models frequently generate semantically incompatible scenes:
kettle in a bathroom, luxury chandelier in a beach shack, toilet visible in kitchen.

```php
final class SemanticConstraint implements Constraint
{
    /**
     * Checks whether assets are semantically compatible with their world.
     * Rule: AssetDefinition.tags ∩ World.forbiddenAssetTags must be empty.
     *
     * Examples of violations:
     *   kettle + bathroom_world     → ERROR: SEMANTIC_MISMATCH
     *   chandelier + beach_world    → WARNING: SEMANTIC_UNLIKELY
     *   toilet visible in kitchen   → ERROR: SEMANTIC_MISMATCH
     */
    public function validate(SceneGraph $graph, FrozenProductionBible $bible): ConstraintResult { ... }
}
```

#### CameraConstraint — lens + movement coherence

```php
final class CameraConstraint implements Constraint
{
    /**
     * Validates camera choices against physical/cinematic rules.
     *
     * Violations:
     *   closeup + 24mm focal          → WARNING: DISTORTION_RISK
     *                                   (wide angle distorts faces)
     *   extreme telephoto + handheld  → ERROR: BLUR_RISK
     *                                   (200mm+ + handheld = unusable)
     *   DOF=shallow + STATIC          → INFO: consider motivating the shallow DOF
     *   fast pan + shallow DOF        → WARNING: subject will be lost
     */
    public function validate(SceneGraph $graph, FrozenProductionBible $bible): ConstraintResult { ... }
}
```

#### LightingConstraint — temporal/physical lighting coherence

```php
final class LightingConstraint implements Constraint
{
    /**
     * Validates lighting across shots within a scene.
     *
     * Violations:
     *   GOLDEN_HOUR + overhead_harsh_sun   → ERROR: LIGHTING_IMPOSSIBLE
     *                                        (golden hour has no overhead sun)
     *   INTERIOR + rain_world              → WARNING: windows should show rain
     *   Shot 1: MOONLIGHT / Shot 2: NOON   → ERROR: TEMPORAL_LIGHTING_JUMP
     *   CANDLELIT + cold_blue_palette      → WARNING: color_mismatch
     */
    public function validate(SceneGraph $graph, FrozenProductionBible $bible): ConstraintResult { ... }
}
```

#### EmotionConstraint — emotion vs. body language coherence

```php
final class EmotionConstraint implements Constraint
{
    /**
     * Validates that CharacterState.emotion is compatible with acting descriptors.
     *
     * Violations:
     *   emotion=DISGUST + body_signal=smiling    → ERROR: EMOTION_BODY_MISMATCH
     *   emotion=TERROR + body_signal=relaxed     → ERROR: EMOTION_BODY_MISMATCH
     *   emotion=JOY + body_signal=crying         → WARNING: may be intentional (tears of joy)
     *   emotion=CRYING + goal_type=SHOWCASE      → WARNING: emotion/goal tension
     */
    public function validate(SceneGraph $graph, FrozenProductionBible $bible): ConstraintResult { ... }
}
```

### Updated ConstraintEngine with 8 built-in constraints

```php
final class ConstraintEngine
{
    public static function defaults(): self
    {
        return new self([
            // Original 4 (ADR-002)
            new SpatialConstraint(),
            new TemporalConstraint(),
            new StateTransitionConstraint(),
            new CharacterContinuityConstraint(),
            // New 4 (Amendment D)
            new SemanticConstraint(),
            new CameraConstraint(),
            new LightingConstraint(),
            new EmotionConstraint(),
        ]);
    }
}
```

### Constraint severity matrix

| Constraint | ERROR (blocks) | WARNING (logged) | INFO (hint) |
|-----------|---------------|-----------------|-------------|
| Spatial | Character in 2 places | Same location, different time | — |
| Temporal | Night→Day→Night | 6h jump | Minor time skip |
| StateTransition | Door state change without action | — | State unchanged for 10+ shots |
| CharacterContinuity | — | Wardrobe change without transition | Minor accessory |
| Semantic | Object in impossible environment | Object in unlikely environment | Unusual placement |
| Camera | Telephoto + handheld | Wide + closeup | DOF note |
| Lighting | Physically impossible | Color/source mismatch | Subtle note |
| Emotion | Emotion ≠ body language | Ambiguous emotion | Goal/emotion tension |

---

## References

- ADR-001: Freeze AFOS Compiler Core
- ADR-003: FilmOS Extended Engines
- ADR-004: Production Event Bus
- ADR-005: Persistence Model
- `app/Services/AI/SceneGraph/GraphAssembler.php` — migration target
- `app/Services/AI/SceneGraph/ContinuityEngine.php` — superseded by CharacterContinuityConstraint
- `app/Services/AI/AFOS/Ir/ShotGoalIR.php` — contract boundary (input to AFOS)
- `app/Services/AI/AFOS/Ir/PromptIRSnapshot.php` — contract boundary (output from AFOS)
- `app/Services/AI/Contracts/RenderContext.php` — Amendment A
