# ADR-003: FilmOS Extended Engines

**Status:** Proposed — Amended 2026-07-06  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-001, ADR-002  
**Amended by:** Amendment E (VisualMemory sub-types), Amendment F (EditingOS independence)

---

## Context

ADR-002 defines FilmOS Core (Bible, WorldModel, Character, Asset, SceneGraph,
ConstraintEngine, PlanningContext). These answer:

> "What exists in this production and what are the rules?"

But to reach video quality comparable to a real studio production, six additional
engines are required. These answer a different question:

> "How does this production *look*, *move*, *act*, and *feel*?"

The gap between "technically correct prompts" and "cinematically coherent film" is
precisely these six layers. Without them, AFOS produces prompts. With them, FilmOS
directs films.

---

## Decision

Extend `app/Services/AI/FilmOS/` with six engine namespaces:

| Engine | Namespace | Answers |
|--------|-----------|---------|
| Visual Language | `FilmOS/VisualLanguage/` | "Every shot looks like the same director made it" |
| Acting | `FilmOS/Acting/` | "Characters behave like humans, not NPCs" |
| Motion Director | `FilmOS/Motion/` | "Camera movement matches the domain and emotion" |
| Editing AI | `FilmOS/Editing/` | "Shots cut together as a film, not a slideshow" |
| Character Brain | `FilmOS/Character/Brain/` | "Characters have personality that drives behavior" |
| Visual Memory | `FilmOS/Memory/` | "Shot 18 has the same sofa, cup, and woman as Shot 3" |

---

## Engine 1: Visual Language Engine

### Problem

`DirectorProfile` and `CinematographyProfile` (AFOS Creative/) hold high-level
philosophy. They do not enforce concrete shot-level rules:

- Roger Deakins never uses a focal length below 32mm for interiors
- Villeneuve always uses overhead + ground-level alternation for reveals
- Fincher never allows handheld in non-action scenes

Without a rule system, shots that reference the same `DirectorProfile` can still
produce wildly inconsistent visuals.

### Solution: VisualLanguage is a rule engine over StyleBible

```php
namespace App\Services\AI\FilmOS\VisualLanguage;

/**
 * Enforces cinematographic consistency across all shots in a production.
 * Reads StyleBible; produces VLConstraints that PlanningContext must satisfy.
 *
 * This is NOT a camera planner — it is a grammar.
 * The grammar constrains what AFOS camera planners are allowed to produce.
 */
final class VisualLanguageEngine
{
    public function grammarFor(StyleBible $style): VisualGrammar { ... }
    public function validate(CameraIR $camera, VisualGrammar $grammar): VLValidationResult { ... }
}
```

### Seven Bibles (sub-modules)

#### LensBible
```php
final class LensBible
{
    /** Allowed focal lengths for this production (mm equivalent) */
    public readonly array  $allowedFocals;       // [32, 40, 50, 65]
    public readonly int    $defaultFocal;         // 40
    public readonly int    $intimateFocal;        // 65  (closeups, reactions)
    public readonly int    $environmentFocal;     // 32  (establishing)
    public readonly float  $minAperture;          // 1.4 (never stop down past f/2.8)
    public readonly float  $maxAperture;          // 2.8
    public readonly bool   $preferShallowDOF;
    public readonly bool   $allowTelephotoCompression; // Deakins: no. Villeneuve: yes.
}
```

#### LightingBible
```php
final class LightingBible
{
    public readonly LightingApproach $approach;       // PRACTICAL, STYLIZED, NATURAL
    public readonly bool   $useNegativeFill;          // Deakins: always
    public readonly bool   $allowHarshContrast;       // Fincher: yes. Nolan: no.
    public readonly string $shadowColorTendency;      // "cyan-teal" | "warm amber" | "neutral"
    public readonly string $skinToneTreatment;        // "warm orange" | "desaturated" | "neutral"
    public readonly float  $maxExposureDelta;         // stops between shadow/highlight
    /** Light sources that ARE allowed */
    public readonly array  $allowedSources;           // ["practical", "window", "motivated"]
    /** Light sources that are NEVER used */
    public readonly array  $forbiddenSources;         // ["on-axis fill", "ring light"]
}
```

#### CompositionBible
```php
final class CompositionBible
{
    public readonly array  $allowedRules;          // [RULE_OF_THIRDS, GOLDEN_RATIO, SYMMETRY]
    public readonly bool   $preferSymmetryForPower; // Kubrick: always. Deakins: rarely.
    public readonly bool   $allowCenteredSubject;   // for what emotion codes?
    public readonly array  $centeredEmotions;       // [AWE, ISOLATION, CONFRONTATION]
    public readonly DepthLayerPreference $depth;   // foreground_heavy | balanced | background
    public readonly bool   $requireForegroundElement; // Villeneuve: almost always
    public readonly float  $minNegativeSpaceRatio;  // 0.0–1.0
}
```

#### MovementBible
```php
final class MovementBible
{
    /** Movements allowed in this production */
    public readonly array  $allowedMoves;          // [SLOW_DOLLY, CRANE, ORBIT, STATIC]
    /** Movements forbidden regardless of emotion */
    public readonly array  $forbiddenMoves;        // [HANDHELD, FAST_PAN, WHIP_PAN]
    public readonly bool   $preferParallax;        // foreground/background separation via move
    public readonly float  $maxMovementSpeed;      // 0.0–1.0 (normalized)
    public readonly bool   $allowRevealMoves;      // slow push from behind obstacle
    /** By domain: luxury always slow. Sports can be fast. */
    public readonly array  $domainOverrides;       // Domain → MovementBible
}
```

#### ColorBible
```php
final class ColorBible
{
    public readonly string      $name;              // "Fincher Cool"
    public readonly ColorRange  $shadows;           // target color for shadows
    public readonly ColorRange  $highlights;        // target color for highlights
    public readonly float       $saturation;        // 0.0–2.0
    public readonly float       $contrast;
    public readonly array       $forbiddenColors;   // ["neon green", "pure red"]
    public readonly array       $signatureColors;   // ["muted navy", "warm ivory"]
    public readonly bool        $monochromaticTendency;
}
```

#### FocusBible
```php
final class FocusBible
{
    public readonly bool  $allowRackFocus;          // focus pull mid-shot
    public readonly bool  $preferSubjectInFocus;    // always? or selective?
    public readonly bool  $allowSoftFocus;          // dreamy effect
    public readonly array $rackFocusEmotions;       // REVEAL, REALIZATION
    public readonly DOFLevel $defaultDOF;
}
```

#### TransitionBible
```php
final class TransitionBible
{
    public readonly TransitionType $defaultCut;    // HARD_CUT | J_CUT | L_CUT
    public readonly bool  $preferMatchCuts;
    public readonly bool  $allowDissolves;         // Fincher: almost never. Malick: frequently.
    public readonly bool  $allowSmashCuts;
    public readonly float $defaultCutDurationMs;   // how long before cut
    public readonly array $emotionToCutType;       // Emotion → TransitionType
}
```

### Integration point

`VisualLanguageEngine` runs **after** AFOS produces `CameraIR` and **before** `BackendStage`:

```
[AFOS: Tier2Stage] → CameraIR
        │
        ▼ (new validation step, outside AFOS)
[FilmOS: VisualLanguageEngine.validate(CameraIR, grammar)]
        │ VLValidationResult
        ▼
[AFOS: CameraValidationStage] (existing)
        │
        ▼
[AFOS: Tier3Stage] → PromptIR
```

If `VLValidationResult` has violations, the `ShotPlanner` adjusts `ShotGoalIR`
and recompiles — or flags the violation for `DirectorAI` to resolve.

---

## Engine 2: Acting Engine

### Problem

AI video models receive "woman shocked" and produce generic, flat reactions.
Real filmmakers decompose emotion into physical grammar.

### ActingGraph

Each `Emotion` maps to an `ActingGraph` — a set of simultaneous body signals:

```php
namespace App\Services\AI\FilmOS\Acting;

final class ActingGraph
{
    public readonly Emotion       $emotion;
    /** @var BodySignal[] — simultaneous physical expressions */
    public readonly array         $signals;
    /** Sequential beat: what happens first vs. last */
    public readonly array         $sequence;   // ["eye_widen → brow_raise → step_back"]
    public readonly float         $peakMs;     // when peak expression hits (0.0–duration)
    public readonly float         $decayMs;    // how long to sustain before resolving
}

final class BodySignal
{
    public readonly BodyRegion  $region;    // EYES, BROWS, MOUTH, NECK, SHOULDERS, HANDS
    public readonly string      $description; // "eyes widen, whites visible"
    public readonly float       $intensity;   // 0.0–1.0
    public readonly float       $delayMs;     // offset from emotion onset
}
```

### ActingLibrary — built-in graphs

```php
enum Emotion {
    case SHOCK:    ActingGraph([
        BodySignal(EYES,      "eyes widen, pupils dilate",                0.9, 0ms),
        BodySignal(BROWS,     "both brows raise to hairline",             0.8, 30ms),
        BodySignal(MOUTH,     "jaw drops slightly open",                  0.7, 80ms),
        BodySignal(SHOULDERS, "shoulders drop and pull back",             0.5, 120ms),
        BodySignal(HANDS,     "hands freeze or rise toward face",         0.6, 150ms),
        BodySignal(NECK,      "neck extends forward then retracts",       0.4, 200ms),
    ], sequence: ["freeze → widen → gasp → step_back"], peakMs: 800, decayMs: 2000);

    case DISGUST:  ActingGraph([
        BodySignal(NOSE,      "nose wrinkles, nostrils flare",            0.9, 0ms),
        BodySignal(MOUTH,     "upper lip curls, mouth corners pull down", 0.8, 50ms),
        BodySignal(EYES,      "eyes squint slightly",                     0.6, 80ms),
        BodySignal(HEAD,      "head tilts and pulls away from stimulus",  0.7, 100ms),
        BodySignal(HANDS,     "hands pull back or cover nose",            0.5, 150ms),
        BodySignal(BODY,      "torso recoils, weight shifts backward",    0.6, 200ms),
    ], sequence: ["smell → recoil → step_back → look_away"], peakMs: 600, decayMs: 1500);
}
```

### Integration with RenderContext

`ActingEngine` enriches `CharacterRenderDescriptor` with the decomposed acting signal
before it enters `RenderContext`:

```php
final class ActingEngine
{
    public function enrich(
        CharacterRenderDescriptor $descriptor,
        Emotion                   $emotion,
        ActingLibrary             $library,
    ): CharacterRenderDescriptor
    {
        $graph   = $library->graphFor($emotion);
        $signals = $graph->toPromptPhrases(); // ["eyes wide, jaw drops", "shoulders recoil"]

        return $descriptor->withActingSignals($signals);
    }
}
```

The enriched descriptor flows into `RenderContext → BackendEmitter → Kling prompt`.

---

## Engine 3: Motion Director

### Problem

`MotionPlanner` (AFOS) plans camera motion in isolation.
It does not know that luxury villa never uses fast pan,
or that sports can use handheld + whip pan.

This is domain-specific **camera grammar** — not compiler logic.

### MotionLibrary

```php
namespace App\Services\AI\FilmOS\Motion;

final class MotionLibrary
{
    /** @var DomainMotionProfile[] */
    private array $profiles;

    public function profileFor(Domain $domain, StyleBible $style): DomainMotionProfile { ... }
    public function defaults(): self { ... }
}

final class DomainMotionProfile
{
    public readonly Domain      $domain;

    // Preferred moves (in priority order)
    public readonly array       $preferredMoves;     // [FLOATING_CRANE, PARALLAX, SLOW_ORBIT]

    // Never use
    public readonly array       $forbiddenMoves;     // [FAST_PAN, WHIP_PAN, HANDHELD]

    // When to use reveals
    public readonly array       $revealTriggers;     // [ESTABLISH_SHOT, CLIMAX_SHOT]

    // Speed modifiers
    public readonly float       $maxVelocity;        // 0.0–1.0
    public readonly float       $defaultVelocity;    // 0.2 for luxury, 0.6 for sports

    // Secondary motion (what happens in background)
    public readonly array       $preferredSecondary; // [WATER_RIPPLE, LEAF_MOVEMENT]
}
```

### Built-in profiles

| Domain | Preferred | Forbidden | Velocity |
|--------|-----------|-----------|----------|
| `luxury_villa` | floating crane, parallax, slow orbit, foreground reveal | fast pan, handheld, whip pan | 0.1–0.3 |
| `superyacht` | slow dolly, crane, wide orbit | fast moves, shaky cam | 0.1–0.25 |
| `automotive` | orbit, track, crane, fly-through | static, handheld | 0.4–0.8 |
| `sports` | handheld, track, fast pan, POV | static crane | 0.5–1.0 |
| `architecture` | slow tilt, parallax, symmetry hold | handheld, zoom | 0.05–0.2 |

### Integration

`MotionLibrary` is consulted by `ShotPlanner` when building `ShotGoalIR.motionHint`:

```php
// DefaultShotPlanner — enriched version (Phase D)
$motionProfile = $this->motionLibrary->profileFor($context->world->domain, $context->style);
$motionHint    = $motionProfile->selectMoveFor($context->shot->emotion, $context->shot->goalType);
```

---

## Engine 4: Editing AI

### Problem

AFOS ends at `PromptIRSnapshot` — one prompt per shot.
But a film is not a sequence of prompts. It is a sequence of cuts.

The editing layer determines:
- When to cut
- What type of cut
- How to sync with music
- What the rhythm of the whole piece feels like

### EditDecisionList (EDL)

```php
namespace App\Services\AI\FilmOS\Editing;

final class EditDecisionList
{
    /** @var EditEntry[] */
    public readonly array  $entries;
    public readonly float  $totalDurationMs;
    public readonly string $musicCueId;      // optional sync reference
}

final class EditEntry
{
    public readonly string         $shotId;
    public readonly float          $inPointMs;    // where in the shot to start
    public readonly float          $outPointMs;   // where to cut out
    public readonly float          $timelineStartMs; // position on master timeline
    public readonly TransitionType $transitionIn;
    public readonly TransitionType $transitionOut;
    public readonly ?MusicBeat     $syncBeat;     // snap to music beat?
}
```

### RhythmPlanner

Converts `SceneGraph` (with emotion arc) into an `EditDecisionList`:

```php
final class RhythmPlanner
{
    /**
     * Plans shot rhythm based on narrative phase + emotion curve.
     *
     * SETUP:   longer shots, L-cuts, breathe
     * BUILD:   progressive shortening, match cuts
     * CLIMAX:  short, aggressive, music-aligned
     * RESOLVE: longer again, dissolves allowed
     */
    public function plan(SceneGraph $graph, StyleBible $style): EditDecisionList { ... }
}
```

### BeatAligner

Snaps cut points to music beats:

```php
final class BeatAligner
{
    public function align(
        EditDecisionList $edl,
        MusicTrack       $music,
        float            $snapThresholdMs = 100.0,
    ): EditDecisionList { ... }
}
```

### Cut types implemented

| Type | When used | Description |
|------|-----------|-------------|
| `HARD_CUT` | Default | Immediate frame change |
| `J_CUT` | Dialog/reaction setup | Audio of next shot starts before video cut |
| `L_CUT` | Emotional continuity | Audio of current shot bleeds into next |
| `MATCH_CUT` | Action continuity | Motion or shape matches across cut |
| `SMASH_CUT` | Shock/surprise | Abrupt cut for impact |
| `DISSOLVE` | Time passage, dream | Cross-fade |

---

## Engine 5: Character Brain

### Problem

`CharacterState` tracks what a character looks like.
It does not explain *why* they move the way they do.

Without personality, characters are visually consistent but behaviorally random.
Two shots of the same character — one aggressive, one timid — are inconsistent
even if the wardrobe matches.

### CharacterBrain

```php
namespace App\Services\AI\FilmOS\Character\Brain;

final class CharacterBrain
{
    public readonly string           $characterId;
    public readonly PersonalityType  $personality;  // INTROVERT, DOMINANT, ANXIOUS, COMPOSED...
    public readonly float            $confidence;   // 0.0–1.0
    public readonly float            $aggression;   // 0.0–1.0
    public readonly float            $openness;     // 0.0–1.0 (gestures, eye contact)
    public readonly GestureVocab     $gestureStyle; // SMALL, MODERATE, EXPRESSIVE
    public readonly EyeContactStyle  $eyeContact;   // AVOIDS, NEUTRAL, DIRECT, INTENSE
    public readonly WalkingStyle     $gait;         // SLOW_DELIBERATE, BRISK, HESITANT
    public readonly SpacePreference  $proxemics;    // how close they stand to others
}

final class GestureVocab
{
    public readonly float  $rangeOfMotion;    // 0.0–1.0 (small vs. large gestures)
    public readonly float  $frequency;        // how often they gesture
    public readonly bool   $touchesFace;      // nervous habit?
    public readonly bool   $usesHands;        // for emphasis?
    public readonly array  $signatureGestures; // ["tucks hair", "clasps hands", "tilts head"]
}
```

### BehaviorPlanner

Maps `CharacterBrain + Emotion + GoalType → ActingBehavior`:

```php
final class BehaviorPlanner
{
    /**
     * Given a character's personality and current emotional state,
     * select the APPROPRIATE acting behavior for this shot.
     *
     * Introvert + SHOCK → small, inward collapse (not dramatic arms-out)
     * Dominant + SHOCK  → freeze then aggressive reorientation
     */
    public function plan(
        CharacterBrain $brain,
        Emotion        $emotion,
        ShotContext    $shotContext,
    ): ActingBehavior { ... }
}
```

### Integration

`BehaviorPlanner` runs before `ActingEngine`:

```
CharacterBrain + Emotion
        │
        ▼
BehaviorPlanner → ActingBehavior (personality-filtered signals)
        │
        ▼
ActingEngine → enriched CharacterRenderDescriptor
        │
        ▼
RenderContext → BackendEmitter
```

---

## Engine 6: Visual Memory

### Problem

Shot 1: hotel room with navy sofa, white walls, afternoon sun.
Shot 18 (same scene): hotel room described generically → AI generates different sofa.

Visual Memory stores **embeddings** of key visual elements and retrieves them
for subsequent shots.

### VisualMemoryStore

```php
namespace App\Services\AI\FilmOS\Memory;

final class VisualMemoryStore
{
    public function remember(MemoryEntry $entry): void { ... }
    public function retrieve(MemoryQuery $query): MemoryEntry[] { ... }
    public function retrieveForShot(ShotNode $shot, FrozenProductionBible $bible): RetrievedMemory { ... }
}

final class MemoryEntry
{
    public readonly string      $id;
    public readonly string      $entityId;        // CharacterDefinition::$id or AssetDefinition::$id
    public readonly MemoryType  $type;            // CHARACTER, ASSET, WORLD, LIGHTING
    public readonly string      $descriptor;      // natural language description (locked)
    public readonly ?string     $referenceImageId; // hash → stored image
    public readonly array       $embedding;        // float[] — semantic embedding
    public readonly string      $createdAtShotId;  // which shot first established this
}

final class MemoryQuery
{
    public readonly MemoryType  $type;
    public readonly string      $entityId;
    public readonly ?float[]    $semanticEmbedding;  // for similarity retrieval
    public readonly int         $topK;               // how many entries to return
}

final class RetrievedMemory
{
    /** @var CharacterRenderDescriptor[] — enriched with memory-locked descriptors */
    public readonly array $characters;
    /** @var WorldDescriptor — memory-locked world snapshot */
    public readonly WorldDescriptor $world;
    /** @var AssetRenderDescriptor[] */
    public readonly array $assets;
}
```

### Memory lifecycle

```
Shot 1
    │
    ├── [record] Character appearance → MemoryEntry
    ├── [record] World state (lighting, palette) → MemoryEntry
    └── [record] Key assets (sofa, cup, window) → MemoryEntry

Shots 2–17
    │
    └── [retrieve] relevant MemoryEntries → lock descriptors in RenderContext

Shot 18
    │
    └── [retrieve] same sofa, same woman, same lighting
        RenderContext gets memory-locked descriptors
        → Kling prompt includes exact same visual anchors
```

### Phase E storage backends

| Phase | Backend | Notes |
|-------|---------|-------|
| E1 | In-memory array | Development only |
| E2 | JSON snapshots per `VideoProject` | Persistent, no vector search |
| E3 | PostgreSQL + pgvector | Semantic similarity retrieval |
| E4 | External embedding model | OpenAI / Cohere for image embeddings |

---

## Updated FilmOS Directory Structure

```
app/Services/AI/FilmOS/
├── Bible/                  ← ADR-002 Phase B1
│   ├── ProductionBible.php
│   ├── StyleBible.php
│   │   ├── LensBible.php
│   │   ├── LightingBible.php
│   │   ├── CompositionBible.php
│   │   ├── MovementBible.php
│   │   ├── ColorBible.php
│   │   ├── FocusBible.php
│   │   └── TransitionBible.php
│   └── ...
├── World/                  ← ADR-002 Phase B2
├── Character/              ← ADR-002 Phase B3
│   ├── CharacterDefinition.php
│   ├── CharacterState.php
│   ├── CharacterRegistry.php
│   └── Brain/              ← ADR-003 Phase D
│       ├── CharacterBrain.php
│       ├── BehaviorPlanner.php
│       ├── GestureVocab.php
│       └── ActingBehavior.php
├── Asset/                  ← ADR-002 Phase B4
├── Scene/                  ← ADR-002 Phase B6
├── Constraint/             ← ADR-002 Phase B5
├── Planning/               ← ADR-002 Phase B7
│   ├── PlanningContext.php
│   ├── ShotPlanner.php
│   └── DefaultShotPlanner.php
├── VisualLanguage/         ← ADR-003 Phase C
│   ├── VisualLanguageEngine.php
│   ├── VisualGrammar.php
│   ├── VLValidationResult.php
│   └── Bibles/
│       ├── LensBible.php      (referenced from Bible/)
│       ├── LightingBible.php
│       ├── CompositionBible.php
│       ├── MovementBible.php
│       ├── ColorBible.php
│       ├── FocusBible.php
│       └── TransitionBible.php
├── Acting/                 ← ADR-003 Phase D
│   ├── ActingEngine.php
│   ├── ActingGraph.php
│   ├── ActingLibrary.php
│   ├── BodySignal.php
│   └── Enums/
│       └── BodyRegion.php
├── Motion/                 ← ADR-003 Phase D
│   ├── MotionLibrary.php
│   ├── DomainMotionProfile.php
│   └── Profiles/
│       ├── LuxuryVillaProfile.php
│       ├── SuperyachtProfile.php
│       ├── AutomotiveProfile.php
│       ├── SportsProfile.php
│       └── ArchitectureProfile.php
├── Editing/                ← ADR-003 Phase F
│   ├── EditDecisionList.php
│   ├── EditEntry.php
│   ├── RhythmPlanner.php
│   ├── BeatAligner.php
│   ├── MusicTrack.php
│   └── Enums/
│       └── TransitionType.php
└── Memory/                 ← ADR-003 Phase E
    ├── VisualMemoryStore.php
    ├── MemoryEntry.php
    ├── MemoryQuery.php
    ├── RetrievedMemory.php
    ├── Enums/
    │   └── MemoryType.php
    └── Backends/
        ├── InMemoryStore.php
        ├── JsonSnapshotStore.php
        └── VectorStore.php         (Phase E3)
```

---

## Updated Phase Roadmap

```
Phase A  [DONE]    Freeze AFOS Compiler Core (ADR-001)
         │
Phase B  [NEXT]    FilmOS Core (ADR-002)
         │         B1: ProductionBible + StyleBible skeleton
         │         B2: WorldModel
         │         B3: CharacterDefinition + CharacterState
         │         B4: AssetDefinition + AssetInstance
         │         B5: ConstraintEngine (4 built-ins)
         │         B6: SceneGraph v2 (SceneNode + ShotNode)
         │         B7: PlanningContext + DefaultShotPlanner
         │         B8: Wire into GraphAssembler
         │
Phase C  ⭐        Visual Language Engine
         │         C1: 7 Bibles (Lens, Lighting, Composition, Movement, Color, Focus, Transition)
         │         C2: VisualGrammar + VisualLanguageEngine.validate()
         │         C3: Integration between AFOS CameraIR and VL validation
         │
Phase D  ⭐⭐⭐    Character Intelligence
         │         D1: CharacterBrain + BehaviorPlanner
         │         D2: ActingGraph + ActingLibrary (SHOCK, DISGUST, FEAR, JOY, CALM...)
         │         D3: ActingEngine → RenderContext enrichment
         │         D4: DomainMotionProfile × 5 domains
         │         D5: MotionLibrary → ShotPlanner integration
         │
Phase E  ⭐⭐⭐⭐  Visual Memory
         │         E1: MemoryEntry + InMemoryStore (dev)
         │         E2: JsonSnapshotStore per VideoProject (persistent)
         │         E3: VectorStore + pgvector (semantic retrieval)
         │         E4: Image embedding pipeline
         │
Phase F  ⭐⭐⭐⭐⭐ Editing AI
         │         F1: TransitionType enum + EditEntry + EDL model
         │         F2: RhythmPlanner (narrative phase → cut timing)
         │         F3: BeatAligner (music sync)
         │         F4: EDL export (Final Cut Pro XML, DaVinci Resolve EDL)
         │
Phase G           Director AI + Producer AI
                  G1: Storyboard generator from SceneGraph
                  G2: Emotion curve planner
                  G3: Budget/quality/latency optimizer
                  G4: Publishing pipeline (voice, subtitle, render queue)
```

---

## Completion Estimate (with extended engines)

| Layer | After Phase B | After Phase C | After Phase D | After Phase E+F |
|-------|--------------|---------------|---------------|-----------------|
| Compiler Core | 98% | 98% | 98% | 98% |
| FilmOS Core | 85% | 88% | 92% | 95% |
| Visual Language | 0% | 90% | 90% | 95% |
| Character Intelligence | 20% | 20% | 85% | 90% |
| Visual Memory | 0% | 0% | 0% | 85% |
| Editing AI | 0% | 0% | 0% | 80% |
| **Video quality vs. sample** | 70% | 80% | 88% | **94–96%** |

---

## Consequences

### Positive
- `VisualLanguageEngine` enforces that every shot in the production looks like the
  same director made it — without changing AFOS
- `ActingEngine` + `CharacterBrain` eliminates "NPC behavior" from AI-generated characters
- `VisualMemory` solves the consistency problem across scenes without reference images
  in every prompt
- `EditingAI` transforms "sequence of prompts" into "edited film"
- All 6 engines are additive — Phase B can ship and produce real value before D/E/F exist

### Negative
- `VisualMemory` in Phase E3+ requires infrastructure (pgvector or equivalent)
- `ActingLibrary` must be hand-tuned per domain — cannot be auto-generated
- `BeatAligner` requires music track metadata (BPM, beat timestamps) from production team
- `VisualLanguageEngine` validation may cause AFOS compilation to fail → retry loop needed

### Priority justification

Phase C before D/E/F because:
- Visual Language is pure rules — zero infrastructure needed
- One StyleBible change instantly improves ALL shots
- Highest ROI per engineering hour for video quality

Phase D before E because:
- Character behavior is visible in every shot
- Visual Memory is invisible unless character is inconsistent first

Phase E before F because:
- Memory consistency must be established before editing can reference consistent shots

---

## Amendment E: VisualMemory Sub-types

> **Amendment date:** 2026-07-06
> **Problem:** Original `VisualMemoryStore` uses a single `MemoryType` enum
> (CHARACTER, ASSET, WORLD, LIGHTING). This is insufficient because different
> visual dimensions need different retrieval strategies.
>
> Example: Shot 1 uses 40mm lens. Shot 18 AI generates 85mm.
> `VisualMemory` must track lens/composition choices, not just objects.

### Five focused memory sub-stores

```php
namespace App\Services\AI\FilmOS\Memory;

/**
 * Root visual memory — aggregate of 5 specialized stores.
 * Each store has its own retrieval logic and storage model.
 */
final class VisualMemoryStore
{
    public function __construct(
        public readonly AppearanceMemory   $appearance,    // faces, hair, body, wardrobe
        public readonly SpatialMemory      $spatial,       // room layout, prop positions, depth
        public readonly LightingMemory     $lighting,      // sources, color grade, mood per shot
        public readonly CompositionMemory  $composition,   // lens, framing, DOF, camera angle
        public readonly AssetMemory        $asset,         // specific props, vehicles, architecture
    ) {}

    public static function empty(): self { ... }

    /** Convenience: retrieve everything relevant for one shot */
    public function forShot(ShotNode $shot, FrozenProductionBible $bible): RetrievedMemory { ... }
}
```

### AppearanceMemory — "same person"

```php
final class AppearanceMemory
{
    /**
     * Records: characterId → locked appearance descriptor + reference image
     * Retrieval: characterId → AppearanceEntry (for CharacterRenderDescriptor enrichment)
     *
     * Locked on first shot where character appears.
     * Never overwritten — only state (wardrobe, injury) changes via CharacterState.
     */
    public function record(string $characterId, AppearanceEntry $entry): void { ... }
    public function get(string $characterId): ?AppearanceEntry { ... }
    public function has(string $characterId): bool { ... }
}

final class AppearanceEntry
{
    public readonly string  $characterId;
    public readonly string  $descriptor;        // "slim woman, brown eyes, long dark wavy hair"
    public readonly ?string $referenceImageId;
    public readonly string  $lockedAtShotId;    // immutable after lock
}
```

### SpatialMemory — "same room"

```php
final class SpatialMemory
{
    /**
     * Records: worldId + sceneId → spatial layout descriptor
     * Retrieval: same worldId → inject layout into RenderContext.worldDescriptor
     *
     * Example: "hotel room, floor-to-ceiling windows on south wall, king bed
     *           against north wall, desk to the left of window"
     */
    public function record(string $worldId, string $sceneId, SpatialEntry $entry): void { ... }
    public function get(string $worldId): ?SpatialEntry { ... }
}
```

### LightingMemory — "same light"

```php
final class LightingMemory
{
    /**
     * Records per-scene lighting state so subsequent shots match.
     * Lighting can evolve (golden hour → dusk → night) so entries
     * are stored with timestamps, not locked.
     *
     * Example: Shot 1 = "golden afternoon sun, warm fill, f/1.8"
     *          Shot 8 = same scene but 30min later = "blue dusk, cooler"
     */
    public function record(string $sceneId, string $shotId, LightingEntry $entry): void { ... }
    public function latest(string $sceneId): ?LightingEntry { ... }
    public function at(string $sceneId, string $shotId): ?LightingEntry { ... }
}

final class LightingEntry
{
    public readonly string    $descriptor;     // "soft window backlight, warm key, cool fill"
    public readonly string    $colorNote;      // "orange skin tone, teal shadows"
    public readonly TimeOfDay $timeOfDay;
    public readonly float     $exposureLevel;  // 0.0 (very dark) → 1.0 (very bright)
}
```

### CompositionMemory — "same lens"

```php
final class CompositionMemory
{
    /**
     * Tracks camera/composition choices to enforce consistency.
     * VisualLanguageEngine uses this to flag lens drift between shots.
     *
     * Example: Shot 1 = 40mm, f/1.8, rule-of-thirds
     *          Shot 18 = 85mm (flagged: LENS_DRIFT from CompositionMemory)
     */
    public function record(string $shotId, CompositionEntry $entry): void { ... }
    public function prevFor(string $sceneId): ?CompositionEntry { ... }
    public function driftReport(string $sceneId): LensDriftReport { ... }
}

final class CompositionEntry
{
    public readonly int           $focalLengthMm;
    public readonly float         $aperture;
    public readonly DOFLevel      $dof;
    public readonly FramingType   $framing;
    public readonly CompositionRule $rule;
    public readonly CameraHeight  $height;
}

final class LensDriftReport
{
    public readonly bool  $hasDrift;
    public readonly int   $focalDeltaMm;    // how much focal length changed
    public readonly array $driftingShots;   // shotIds where drift exceeded threshold
}
```

### AssetMemory — "same kettle"

```php
final class AssetMemory
{
    /**
     * Records per-asset appearance so the same prop/environment
     * looks identical across scenes.
     *
     * Example: "electric_kettle_silver" → "modern stainless steel kettle,
     *           black base, red indicator light, hotel room desk"
     */
    public function record(string $assetId, AssetEntry $entry): void { ... }
    public function get(string $assetId): ?AssetEntry { ... }
    public function allForScene(string $sceneId): array { ... }
}

final class AssetEntry
{
    public readonly string  $assetId;
    public readonly string  $descriptor;        // detailed visual description
    public readonly ?string $referenceImageId;
    public readonly string  $state;             // INTACT | OPEN | CLOSED | BROKEN
    public readonly string  $lockedAtShotId;
}
```

### Updated RetrievedMemory

```php
final class RetrievedMemory
{
    public readonly array           $appearances;    // AppearanceEntry[] per character
    public readonly ?SpatialEntry   $spatial;        // room layout
    public readonly ?LightingEntry  $lighting;       // current lighting state
    public readonly ?CompositionEntry $composition;  // prev shot's lens/framing
    public readonly array           $assets;         // AssetEntry[] for this shot's assets

    public static function empty(): self { ... }
    public function toRenderContextHints(): array { ... }
}
```

---

## Amendment F: EditingOS as Independent Subsystem

> **Amendment date:** 2026-07-06
> **Problem:** `FilmOS/Editing/` is scoped inside FilmOS. Editing is large enough
> to be its own operating concern — and eventually needs to export to Final Cut Pro,
> DaVinci Resolve, Adobe Premiere. Keeping it inside `FilmOS\` couples it to the
> filmmaking model unnecessarily.

### Solution: EditingOS as independent namespace

```
app/Services/AI/
├── AFOS/          ← compiler (frozen)
├── FilmOS/        ← filmmaking model (world, character, scene)
└── EditingOS/     ← editing system (independent)
    ├── EDL/
    │   ├── EditDecisionList.php
    │   ├── EditEntry.php
    │   └── Export/
    │       ├── FinalCutXmlExporter.php
    │       ├── DaVinciEDLExporter.php
    │       └── PremiereXmlExporter.php
    ├── Rhythm/
    │   ├── RhythmPlanner.php
    │   └── NarrativePhaseMap.php
    ├── Beat/
    │   ├── BeatAligner.php
    │   ├── MusicTrack.php
    │   └── BeatGrid.php
    ├── Timeline/
    │   ├── MasterTimeline.php
    │   ├── TimelineTrack.php     (distinct from AFOS TemporalTrack)
    │   └── TimelineEntry.php
    ├── FX/
    │   ├── SoundFX.php
    │   ├── ColorGradeInstruction.php
    │   └── TransitionFX.php
    └── Enums/
        ├── TransitionType.php
        ├── TrackType.php         (VIDEO, AUDIO, SUBTITLE, VOICE, FX)
        └── ExportFormat.php
```

### Boundary: EditingOS ← FilmOS

`EditingOS` receives a `SceneGraph` (from FilmOS) and a `StyleBible` (from FilmOS),
but it is **not imported by** FilmOS. The dependency is one-directional:

```
FilmOS → [produces SceneGraph]
              │
              ▼
         EditingOS.plan(SceneGraph, StyleBible) → EditDecisionList
              │
              ▼
         EditingOS.export(EDL, format) → XML/EDL file
```

### Enabling future integrations

```php
namespace App\Services\AI\EditingOS\EDL\Export;

interface EdlExporter
{
    public function format(): ExportFormat;
    public function export(EditDecisionList $edl): string;
}

final class DaVinciEDLExporter implements EdlExporter { ... }
final class FinalCutXmlExporter  implements EdlExporter { ... }
final class PremiereXmlExporter  implements EdlExporter { ... }
```

---

## Updated Phase Roadmap (with all amendments)

```
Phase A  [DONE]      Freeze AFOS (ADR-001)
         │
Phase B  [NEXT]      FilmOS Core
         │           B1: ProductionBible (Module Pattern) + StyleBible
         │           B2: WorldModel + WorldModule
         │           B3: CharacterDefinition + CharacterState + CharacterModule
         │           B4: AssetDefinition + AssetInstance + AssetModule
         │           B5: ConstraintEngine (8 built-ins: original 4 + Semantic/Camera/Lighting/Emotion)
         │           B6: SceneGraph v2 (SceneNode + ShotNode)
         │           B7: PlanningContext (decomposed) + PlanningContextBuilder
         │           B8: Wire into GraphAssembler
         │
Phase C  ⭐          Visual Language Engine
         │           C1: 7 Bibles in StyleModule
         │           C2: VisualGrammar + VisualLanguageEngine
         │           C3: CameraIR validation hook (post-AFOS, pre-backend)
         │
Phase D  ⭐⭐⭐      Character Intelligence
         │           D1: CharacterBrain + BehaviorPlanner
         │           D2: ActingGraph + ActingLibrary (8 core emotions)
         │           D3: ActingEngine → CharacterContext enrichment
         │           D4: MotionLibrary + DomainMotionProfile × 5
         │
Phase E  ⭐⭐⭐⭐    Visual Memory (5 sub-stores)
         │           E1: AppearanceMemory + AssetMemory (in-memory, dev)
         │           E2: SpatialMemory + LightingMemory + CompositionMemory
         │           E3: JsonSnapshotStore (persistent)
         │           E4: VectorStore + pgvector + embeddings
         │
Phase F  ⭐⭐⭐⭐⭐  EditingOS (independent namespace)
         │           F1: EDL model + TransitionType + MasterTimeline
         │           F2: RhythmPlanner (narrative phase → timing)
         │           F3: BeatAligner (music sync)
         │           F4: LensDriftReport integration with CompositionMemory
         │           F5: Exporters (DaVinci, FinalCut, Premiere)
         │
Phase G              Director AI + Producer AI
                     G1: Storyboard from SceneGraph
                     G2: Emotion curve + pacing optimizer
                     G3: Budget/latency optimizer
                     G4: Full publishing pipeline
```

---

## References

- ADR-001: Freeze AFOS Compiler Core
- ADR-002: FilmOS Unified Model (Amendments A+B+C+D)
- ADR-004: Production Event Bus
- ADR-005: Persistence Model
- `app/Services/AI/AFOS/Creative/DirectorProfile.php` — reused in StyleBible
- `app/Services/AI/SceneGraph/ContinuityEngine.php` — superseded by AppearanceMemory + LightingMemory
- `app/Services/AI/AFOS/Backends/KlingBackend.php` — receives RenderContext (ADR-002 Amendment A)
