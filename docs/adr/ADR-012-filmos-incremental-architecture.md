# ADR-012: FilmOS Incremental Architecture v1.0

**Status:** Accepted  
**Date:** 2026-07-07  
**Deciders:** Chief Architect + Project Lead  
**Supersedes:** ADR-011 (News Video Pipeline V7 — replaced by this unified document)  
**Depends on:** ADR-001 through ADR-010 (AFOS + FilmOS ADR suite)  
**Scope:** Complete architecture specification for production-grade AI video system

---

## Context

ADR-001 through ADR-011 established the theoretical architecture. This ADR unifies all concepts into a single implementable specification by resolving three open questions:

1. **Where does the Decision Ledger live?** — Not Phase 6. It is a cross-cutting foundation. Every subsystem creates decisions; the Ledger must exist from Phase 1 to capture Story, Narrative, Attention, Camera, and Render decisions.

2. **How does uncertainty propagate?** — Each fact carries a confidence score. Confidence degrades as it travels through the pipeline. A shot rendered from a confidence-0.62 fact is different from one rendered from a confidence-0.95 fact. The system must track this and gate on it.

3. **What is the difference between Style and Constraint?** — `DomainStyleProfile` says *should*. `ConstraintEngine` says *MUST NOT*. They are different types of knowledge and must not be conflated. Style is preference-weighted. Constraints are binary vetoes.

Resolving these three questions produces a pipeline that is not just a video generator, but a **Decision Compiler**: every rendering decision has a traceable reason, a confidence score, and an audit record.

```
Knowledge → Decision → Compilation → Rendering → Learning
```

---

## Five-Layer Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  LAYER 1: KNOWLEDGE                                             │
│  FactGraph · ProductionBible · WorldState                       │
│  Source of truth. Nothing enters the pipeline that isn't here.  │
├─────────────────────────────────────────────────────────────────┤
│  LAYER 2: DECISION                                              │
│  StorySelector · NarrativePattern · AttentionFlow               │
│  Decision Ledger (cross-cutting) · ConstraintEngine             │
│  All reasoning happens here. Output: DirectorIntent.            │
├─────────────────────────────────────────────────────────────────┤
│  LAYER 3: COMPILATION  [AFOS — frozen, ADR-001]                 │
│  ShotGoalIR → 16 planners → CameraIR → PromptIR                 │
│  Deterministic. No AI. Takes DirectorIntent as input.           │
├─────────────────────────────────────────────────────────────────┤
│  LAYER 4: RENDERING                                             │
│  CapabilityResolver (ADR-007) · RendererPlugins · QualityEngine │
│  Execution only. No decisions made here.                        │
├─────────────────────────────────────────────────────────────────┤
│  LAYER 5: LEARNING                                              │
│  AssetGraph · Analytics · Decision Replay · Style Learning      │
│  Feeds back into Layer 1 (facts) and Layer 2 (decisions).       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Layer 1: Knowledge

### FactGraph

The single source of truth. Every downstream decision must trace to a `Fact` node.

```php
namespace App\Services\AI\FilmOS\Knowledge;

final class Fact
{
    public function __construct(
        public readonly string    $factId,
        public readonly string    $subject,
        public readonly string    $predicate,
        public readonly string    $object,
        public readonly float     $confidence,     // 0.0–1.0 — NEVER increases after creation
        public readonly string    $sourceOffset,   // char position in source article
        public readonly FactType  $type,           // STAT, ACTION, QUOTE, CONTEXT, OUTCOME, EMOTION
        public readonly ?string   $timestamp,
    ) {}
}
```

**Invariant:** `Fact.confidence` is set at extraction and never increases. It can only be reduced by downstream uncertainty.

**Block rule:** Facts with `confidence < 0.5` are tagged `UNVERIFIED` and cannot be used by any Decision Layer component.

### ProductionBible

Per-production static definitions. Immutable once locked.

```php
final class ProductionBible
{
    public readonly WorldState    $world;       // locations, time-of-day, weather
    public readonly AssetRegistry $assets;      // CharacterAsset[], PropAsset[], EnvironmentAsset[]
    public readonly DomainProfile $domain;      // 'nfl', 'finance', 'travel_warning', etc.
    public readonly string        $bibleVersion;
    public readonly bool          $locked;      // once locked, no mutations
}
```

### WorldState

Current state of the production world — updated shot-by-shot.

```php
final class WorldState
{
    public function __construct(
        public readonly string  $productionId,
        public readonly string  $shotId,          // which shot this state applies to
        public readonly array   $assetStates,     // assetId → StateVector (ADR-008)
        public readonly string  $timeOfDay,
        public readonly string  $location,
        public readonly string  $weatherCondition,
    ) {}
}
```

---

## Layer 2: Decision

This layer is where all reasoning lives. It takes Knowledge Layer inputs and produces `DirectorIntent` — the sole input to the Compilation Layer.

### Decision Ledger (cross-cutting concern)

**The Decision Ledger is not a Phase 6 feature. It is instantiated at the start of every production and receives entries from every Decision Layer component.**

```php
namespace App\Services\AI\FilmOS\Decision;

final class DecisionEntry
{
    public function __construct(
        public readonly string  $entryId,
        public readonly string  $productionId,
        public readonly string  $shotId,
        public readonly string  $decisionType,    // 'story_fact_select', 'narrative_pattern',
                                                  // 'attention_target', 'camera_lens',
                                                  // 'provider_select', 'qa_verdict'
        public readonly mixed   $chosenValue,     // Fact[], 'hotel_object_danger', 85, 'kling_v2', 'PASS'
        public readonly mixed   $rejectedValues,  // what was considered and not chosen
        public readonly array   $reasons,         // DecisionReason[] — why this was chosen
        public readonly float   $inputConfidence, // confidence entering this decision
        public readonly float   $outputConfidence,// confidence leaving this decision (always ≤ input)
        public readonly string  $decidedBy,       // 'StorySelector', 'AttentionFlow', 'RevealPlanner', etc.
        public readonly \DateTimeImmutable $decidedAt,
    ) {}
}

final class DecisionReason
{
    public function __construct(
        public readonly string  $source,    // 'FactGraph', 'DomainStyleProfile', 'ConstraintEngine',
                                            // 'RevealPlanner rule#12', 'Analytics.travel_warning.CTR'
        public readonly string  $rule,      // human-readable: "priority=0.95 → telephoto preferred"
        public readonly float   $weight,    // contribution to final decision: 0.0–1.0
        public readonly float   $evidence,  // strength of evidence: analytics_score=0.84
    ) {}
}

interface DecisionLedger
{
    public function record(DecisionEntry $entry): void;
    public function forShot(string $shotId): array;         // DecisionEntry[]
    public function forProduction(string $productionId): array;
    public function replay(string $productionId): DecisionDAG;
}
```

**Decision DAG — the complete audit trail:**

```
FactGraph [conf=0.81]
  ↓ StorySelector: "chose Fact#3 over Fact#8 because narrativeWeight=0.9 > 0.6"
Story [conf=0.79]
  ↓ NarrativePlanner: "chose pattern=hotel_object_danger, REVEAL beat at position 3"
Pattern [conf=0.77]
  ↓ AttentionFlow: "target=kettle_interior, must_avoid=background"
Attention [conf=0.76]
  ↓ AFOS/RevealPlanner rule#12: "lens=85, SLOW_PUSH, DOF=shallow"
Camera [conf=0.75]
  ↓ CapabilityResolver: "provider=kling_v2, cost=$0.08, QualityTier=HIGH"
Render [conf=0.74]
  ↓ QualityEngine: "score=0.82, PASS"
QA [final_confidence=0.74]
```

---

### Confidence Propagation

**The single most important invariant in the entire system:**

> Confidence can only decrease as information travels through the pipeline. A rendering decision is only as confident as the weakest fact that informed it.

```php
final class ConfidencePropagator
{
    /**
     * Each decision step has a decay rate based on how much information
     * is lost or assumed when moving from one layer to the next.
     */
    private const DECAY_RATES = [
        'story_fact_select'  => 0.02,  // StorySelector loses little (direct fact use)
        'narrative_pattern'  => 0.03,  // pattern matching has small uncertainty
        'attention_target'   => 0.02,  // attention mapping is mostly deterministic
        'camera_lens'        => 0.01,  // AFOS planners are deterministic
        'provider_select'    => 0.01,  // capability matching is deterministic
        'qa_verdict'         => 0.00,  // QA just observes, no new uncertainty
    ];

    public function propagate(float $inputConfidence, string $decisionType): float
    {
        $decayed = $inputConfidence * (1.0 - (self::DECAY_RATES[$decisionType] ?? 0.02));
        return round($decayed, 4);
    }
}
```

**Confidence gate rules:**

| Confidence | Action |
|---|---|
| ≥ 0.80 | Proceed normally |
| 0.70–0.79 | Proceed with `DecisionEntry.reviewFlag = true` (logged) |
| 0.60–0.69 | Proceed but narration must use hedging language ("reportedly", "according to") |
| < 0.60 | Block. `HumanReviewRequired` event fired. Shot held until reviewed. |
| < 0.50 | Block at FactGraph level. Fact never enters pipeline. |

```php
final class ConfidenceGate
{
    public function check(float $confidence, string $stage): GateVerdict
    {
        return match(true) {
            $confidence >= 0.80 => GateVerdict::PASS,
            $confidence >= 0.70 => GateVerdict::PASS_FLAGGED,
            $confidence >= 0.60 => GateVerdict::PASS_HEDGED,
            default             => GateVerdict::BLOCK,
        };
    }
}

enum GateVerdict: string
{
    case PASS         = 'pass';
    case PASS_FLAGGED = 'pass_flagged';    // logs for human review queue
    case PASS_HEDGED  = 'pass_hedged';     // narration uses hedging language
    case BLOCK        = 'block';           // shot held, HumanReviewRequired fired
}
```

---

### ConstraintEngine

**Distinct from DomainStyleProfile.** Style says *should*. Constraint says *MUST NOT*.

```php
interface ConstraintEngine
{
    /**
     * Validates a proposed DirectorIntent before it enters AFOS.
     * Returns violations — any HARD violation blocks compilation.
     */
    public function validate(
        DirectorIntent    $intent,
        WorldState        $world,
        ProductionBible   $bible,
    ): ConstraintReport;
}

final class ConstraintViolation
{
    public function __construct(
        public readonly string           $constraintId,
        public readonly ConstraintLevel  $level,      // HARD (block) or SOFT (warn)
        public readonly string           $description,
        public readonly string           $rule,       // human-readable source rule
    ) {}
}

enum ConstraintLevel: string
{
    case HARD = 'hard';   // blocks pipeline — shot cannot render
    case SOFT = 'soft';   // warning only — logged in DecisionLedger
}
```

**Built-in constraints:**

```php
// config/filmos_constraints.php
return [
    // Physical impossibility constraints
    'no_drone_indoor' => [
        'condition' => 'world.location.type === INDOOR',
        'forbidden' => ['camera_movement' => ['DRONE', 'AERIAL']],
        'level'     => 'HARD',
        'reason'    => 'Drone camera movement is physically impossible indoors',
    ],

    // Attention-driven constraints
    'telephoto_required_high_priority' => [
        'condition' => 'intent.attentionNode.priority > 0.9',
        'forbidden' => ['lens_group' => ['WIDE', 'EXTREME_WIDE']],
        'level'     => 'HARD',
        'reason'    => 'Wide lens dilutes attention when viewer direction priority > 0.9',
    ],

    // Domain tone constraints
    'no_warm_travel_warning' => [
        'condition' => 'bible.domain === travel_warning',
        'forbidden' => ['color_palette' => ['WARM', 'GOLDEN', 'SOFT']],
        'level'     => 'HARD',
        'reason'    => 'Warm palette conflicts with warning/danger emotional tone',
    ],

    'no_slow_fade_breaking_news' => [
        'condition' => 'bible.domain === breaking_news',
        'forbidden' => ['transition' => ['SLOW_FADE', 'DISSOLVE', 'SOFT_CUT']],
        'level'     => 'HARD',
        'reason'    => 'Slow transitions undercut urgency of breaking news',
    ],

    // Confidence gate constraints
    'no_direct_claim_low_confidence' => [
        'condition' => 'intent.sourceConfidence < 0.60',
        'forbidden' => ['narration_style' => ['DECLARATIVE']],
        'level'     => 'HARD',
        'reason'    => 'Declarative narration requires source confidence ≥ 0.60',
    ],

    // Continuity constraints
    'no_costume_change_same_scene' => [
        'condition' => 'world.sceneId === prev.sceneId',
        'forbidden' => ['costume_change' => true],
        'level'     => 'HARD',
        'reason'    => 'Character costume must not change within a single scene',
    ],

    // Soft warnings
    'warn_long_hold_high_energy' => [
        'condition' => 'intent.emotionIntensity > 0.8 AND shot.durationSec > 3.0',
        'level'     => 'SOFT',
        'reason'    => 'Long static shot may dilute emotional intensity > 0.8',
    ],
];
```

**Key design rule:** `ConstraintEngine` runs *before* AFOS compilation. A HARD violation stops the pipeline entirely. The `DecisionLedger` records which constraint fired and why.

---

### StorySelector

Reduces `FactGraph` (120+ facts) to a curated palette of ≤ 12 facts, then scopes to ≤ 3 facts per beat.

```php
final class StorySelector
{
    public function selectPalette(FactGraph $facts, string $domain): FactPalette
    {
        // 1. Require: ≥ 1 OUTCOME fact
        // 2. Require: highest-weight ACTION fact
        // 3. Include: top STAT facts by domain engagement score (from Analytics)
        // 4. Include: QUOTE facts (highest emotional resonance)
        // 5. Fill to 12 with highest-confidence CONTEXT facts
        // Record: StoryDecision in DecisionLedger (why each fact was included/excluded)
    }

    public function scopeToBeat(FactPalette $palette, NarrativeBeat $beat): array // Fact[], max 3
    {
        // Beat type drives fact type preference:
        // HOOK → prefer CONFLICT, OUTCOME, EMOTION facts
        // CLIMAX → prefer ACTION facts with highest narrativeWeight
        // PAYOFF → prefer OUTCOME, QUOTE facts
        // Record: BeatScopeDecision in DecisionLedger
    }
}
```

### NarrativePattern Library

PHP config DAGs per domain. Sonnet fills slots only — never designs structure.

```php
// config/narrative_patterns/{domain}.php
// Each file: array of named patterns with beats + conditions + transitions

// Sonnet's role:
//   Input: NarrativeBeat[] (from pattern) + Fact[] (from beat scope)
//   Output: narration text for this beat only
//   Constraint: narration must not introduce claims not in Fact[]
```

### AttentionFlowGraph

Declares the viewer's attention journey — the *why* behind each shot's existence.

```php
final class AttentionNode
{
    public function __construct(
        public readonly string  $nodeId,
        public readonly string  $must_show,       // → ShotGoalIR.viewerShouldNotice[]
        public readonly string  $must_avoid,      // → ShotGoalIR.viewerShouldIgnore[]
        public readonly string  $visualPurpose,   // ESTABLISH | NARROW | REVEAL | REACT | TEXT
        public readonly float   $priority,        // 0.0–1.0
        public readonly ?string $nextNodeId,      // chain (null = end)
        public readonly ?string $transitionHint,
        public readonly string  $microMomentRef,  // → EventTemplateLibrary
    ) {}
}
```

**Bridge to AFOS (critical):** `must_show/must_avoid` map directly to `ShotGoalIR.viewerShouldNotice/viewerShouldIgnore` — fields already exist in the frozen AFOS compiler. No AFOS modification needed.

### DirectorIntent

The sole output of Layer 2. The sole input to Layer 3 (AFOS).

```php
final class DirectorIntent
{
    public function __construct(
        public readonly string           $productionId,
        public readonly string           $shotId,
        public readonly AttentionNode    $attentionNode,
        public readonly NarrativeBeat    $beat,
        public readonly array            $beatFacts,         // Fact[], max 3
        public readonly float            $sourceConfidence,  // propagated from FactGraph
        public readonly DomainStyleRule  $styleRule,         // from DomainStyleProfile
        public readonly EmotionPoint     $emotionTarget,
        public readonly array            $constraintReport,  // ConstraintViolation[] (SOFT only — HARD already blocked)
        public readonly string           $decisionLedgerId,  // → DecisionLedger session for this shot
    ) {}
}
```

---

## Layer 3: Compilation (AFOS — frozen)

AFOS receives `DirectorIntent` and compiles to `PromptIR`. **No changes to AFOS.**

```
DirectorIntent
  │
  ├─ attentionNode.must_show   → ShotGoalIR.viewerShouldNotice[]
  ├─ attentionNode.must_avoid  → ShotGoalIR.viewerShouldIgnore[]
  ├─ styleRule.preferredLens   → CameraIR.lens (hint for CameraMotivationPlanner)
  ├─ styleRule.movement        → CameraIR.movementType
  ├─ beat.type                 → ShotGoalIR.narrativeFunction
  ├─ emotionTarget             → ShotGoalIR.emotionWeight
  └─ beatFacts[]               → RenderContext (character + world context)
          │
          ▼
  AFOS 16 planners (CameraMotivationPlanner, RevealPlanner, RhythmPlanner...)
          │
          ▼
  CameraIR → CompositionIR → PromptIR
```

AFOS planners make the final micro-decisions. Every planner decision is captured by `DiagnosticBag` (already exists in AFOS) and forwarded to the `DecisionLedger`.

---

## Layer 4: Rendering

```
PromptIR
  ↓
CapabilityResolver (ADR-007)
  → selects provider by CapabilitySpec + BudgetEnvelope
  → records ProviderDecision in DecisionLedger
  ↓
RendererPlugin (KlingBackend, VeoBackend, ...)
  ↓
QualityEngine (ADR-006/010)
  → scores rendered video
  → records QADecision in DecisionLedger
  ↓
DecisionEngine (ADR-010) — for CRITICAL shots: tournament selection
  ↓
ShotDecided event (ADR-010) — canonical "shot done" signal
```

---

## Layer 5: Learning

### AssetRegistry (cross-video memory)

```php
final class AssetDefinition
{
    public function __construct(
        public readonly string    $assetId,
        public readonly AssetType $type,           // CHARACTER, PROP, ENVIRONMENT, COSTUME
        public readonly string    $displayName,
        public readonly array     $visualRefs,      // reference image IDs for renderer
        public readonly array     $attributes,      // domain-specific: jersey_number, helmet_color, etc.
        public readonly array     $motionTemplates, // EventTemplate keys
        public readonly ?string   $voiceProfileId,
        public readonly int       $videoCount,      // how many videos this asset has appeared in
    ) {}
}
```

**CharacterAsset example — Mahomes:**
```
assetId:        'mahomes_patrick_15'
type:           CHARACTER
visualRefs:     ['face_ref_v3.jpg', 'body_ref_v2.jpg']
attributes:     { jersey_number: '15', helmet: 'red_gold', team: 'chiefs' }
motionTemplates: ['nfl_throw_pass', 'nfl_celebration', 'nfl_scramble']
voiceProfileId: null   # no TTS needed for sports
videoCount:     47     # appeared in 47 videos — high confidence visual refs
```

### EventTemplate Library

```php
// config/news_event_templates.php — 30–50 entries, one PHP file

// Each template: array of micro-moment labels in sequence
// e.g. 'nfl_throw_pass' => ['stance_shift','step_plant','arm_raise','release','follow_through']
// e.g. 'hotel_room_check' => ['enter_space','scan_room','focus_object','inspect_detail']
// e.g. 'object_reveal' => ['establish_container','open_action','interior_visible','reaction']
```

### DomainStyleProfile

```php
// config/filmos_domain_styles.php
// Per-domain, per-beat-type: lens preferences, movement preferences, cut rhythm, color palette
// This is preference (should) — not constraint (must not)
// Feeds into DirectorIntent.styleRule
// Updated weekly by Analytics feedback
```

### Decision Replay

```php
final class DecisionReplay
{
    /**
     * Given a production's DecisionDAG + Analytics result,
     * compute which decisions correlated with high CTR/watch time.
     * Feed back into DomainStyleProfile and NarrativePattern weights.
     */
    public function analyze(DecisionDAG $dag, AnalyticsResult $result): StyleLearningDelta { ... }
}
```

**Learning feedback loop:**

```
Video published
  ↓
Analytics: CTR=8.2%, watchPct=0.87, engagementRate=0.43
  ↓
DecisionReplay.analyze(dag, analytics)
  → "lens=85 + visualPurpose=REVEAL + domain=travel_warning → CTR=8.2%"
  → "pattern=hotel_object_danger → watchPct=0.87"
  ↓
StyleLearningDelta:
  DomainStyleProfile.travel_warning.REVEAL.lens=[85,100].score += 0.08
  NarrativePattern.hotel_object_danger.ranking += 0.05
  ↓
Next video in domain=travel_warning: uses updated weights automatically
```

---

## Data Lifecycle

```
Article arrives
  │
  ├─[1] FactExtractor → FactGraph (confidence per fact, source offset)
  │
  ├─[2] StorySelector → FactPalette (12 facts max) + StoryDecision → Ledger
  │
  ├─[3] NarrativePlanner → NarrativeGraph (pattern selected) + NarrativeDecision → Ledger
  │
  ├─[4] BeatContextBuilder → per beat: max 3 facts scoped
  │
  ├─[5] AttentionFlowGraph builder → AttentionNode chain
  │
  ├─[6] ConstraintEngine.validate(intent, world, bible)
  │        → HARD violation → block → HumanReviewRequired
  │        → SOFT violation → warn → continue
  │
  ├─[7] Confidence propagated at each step → ConfidenceGate
  │        → < 0.60 → block → HumanReviewRequired
  │
  ├─[8] DirectorIntent assembled → Ledger entry
  │
  ├─[9] AFOS compiles (frozen, 16 planners) → DiagnosticBag → Ledger
  │
  ├─[10] CapabilityResolver selects provider → Ledger
  │
  ├─[11] RendererPlugin renders → video URL
  │
  ├─[12] QualityEngine scores → QualityReport → Ledger
  │
  ├─[13] DecisionEngine selects winner (if multi-candidate) → ShotDecided
  │
  ├─[14] VideoPublished event
  │
  └─[15] Analytics ingested → DecisionReplay → StyleLearningDelta
```

---

## Implementation Phases

The five-layer architecture is implemented in phases, ordered by ROI:

### Phase 1 — Knowledge Foundation (2–3 weeks)
*Goal: Ground all decisions in verified facts. Zero hallucination.*

| Component | Layer | Files |
|---|---|---|
| `FactExtractor` | Knowledge | Haiku extraction, SPO triples |
| `FactGraph` | Knowledge | about(), ofType(), verifies(), topN() |
| `StorySelector` | Decision | palette → 12 facts, beat scoping → 3 facts |
| `ConfidencePropagator` | Decision | decay rates per step |
| `ConfidenceGate` | Decision | gate rules: 0.80/0.70/0.60 thresholds |
| `DecisionLedger` (foundation) | Decision | record(), forShot(), forProduction() |
| `VerificationEngine` (3-tier) | Decision | PHP_EXACT → PHP_NLP → AI_JUDGE |

**Deliverable:** Every narration claim traces to a fact. Confidence < 0.60 holds for human review.

---

### Phase 2 — Decision Unlock (1–2 weeks)
*Goal: Unlock the 16 existing AFOS planners. Replace hardcoded DSL.*

| Component | Layer | Files |
|---|---|---|
| `DomainStyleProfile` | Decision | config/filmos_domain_styles.php |
| `ConstraintEngine` | Decision | config/filmos_constraints.php + validator |
| `AttentionFlowGraph` | Decision | AttentionNode chain builder |
| `IntentGraphBuilder` | Decision | AttentionNode → ShotGoalIR bridge |

**Key change:** Remove `DSLBuilder.PURPOSE_DSL` hardcode. Replace with `DomainStyleProfile` lookup → `DirectorIntent.styleRule`. `CameraMotivationPlanner`, `RevealPlanner`, `RhythmPlanner`, `CuriosityPlanner`, `EmotionArcPlanner`, `CameraEnergyPlanner` all activate correctly.

**Deliverable:** Visual language varies by domain. Camera decisions have reasons. Constraints prevent physically/tonally impossible shots.

---

### Phase 3 — Event Decomposition (1–2 weeks)
*Goal: Decompose news events into observable micro-moments.*

| Component | Layer | Files |
|---|---|---|
| `EventTemplateLibrary` | Knowledge | config/news_event_templates.php (30–50 entries) |
| `EventBuilder` | Decision | Fact → EventTemplate → MicroMoment[] |

**Deliverable:** A "Mahomes throws TD" fact decomposes to 5 micro-moments, each with an `AttentionNode` telling AFOS what to show.

---

### Phase 4 — Narrative Structure (2 weeks)
*Goal: Story structure per domain. Sonnet fills slots only.*

| Component | Layer | Files |
|---|---|---|
| `NarrativePatternLibrary` | Decision | config/narrative_patterns/{domain}.php |
| `NarrativePlanner` | Decision | Sonnet: beat fill given ≤ 3 facts + pattern beat |

**Deliverable:** `hotel_object_danger`, `nfl_comeback_win`, `finance_market_surge` patterns produce structured, domain-appropriate video sequences.

---

### Phase 5 — Asset Graph (3–4 weeks)
*Goal: Cross-video character and asset consistency.*

| Component | Layer | Files |
|---|---|---|
| `AssetDefinition` | Knowledge | CHARACTER / PROP / ENVIRONMENT / COSTUME |
| `AssetRegistry` | Knowledge | persistent cross-video store |
| `CharacterProfiler` | Decision | maps article subjects → AssetDefinition |

**Deliverable:** Mahomes in video 47 has the same visual refs as video 1. Asset Graph feeds directly into `RenderContext`.

---

### Phase 6 — Learning Loop (ongoing)
*Goal: System improves from production data.*

| Component | Layer | Files |
|---|---|---|
| `AnalyticsIngester` | Learning | published video → analytics ingest |
| `DecisionReplay` | Learning | DecisionDAG + Analytics → StyleLearningDelta |
| `StyleLearning` | Learning | delta → DomainStyleProfile weight updates |

**Deliverable:** Style rules, narrative pattern rankings, and fact selection weights improve automatically from CTR/watch-time data.

---

## Phase Timeline

| Phase | Content | Weeks | Quality Impact |
|---|---|---|---|
| **1** | FactGraph + Verification + Decision Ledger + Confidence | 2–3 | Hallucination=0 |
| **2** | DomainStyleProfile + ConstraintEngine + AttentionFlow | 1–2 | 16 planners active, visual direction |
| **3** | EventTemplate Library (30–50 entries) | 1–2 | Micro-moment visual sequences |
| **4** | NarrativePattern Library (3–4 domains) | 2 | Professional story structure |
| **5** | AssetGraph + AssetRegistry | 3–4 | Cross-video character consistency |
| **6** | DecisionReplay + StyleLearning | ongoing | Self-improving |
| **Total** | | **10–13 weeks** | Production-grade |

---

## Quality Projection

| After Phase | Architecture Score | Video Quality | Key Capability |
|---|---|---|---|
| AFOS today | 8.8/10 | 6.5/10 | Engine exists but DSL-blocked |
| + Phase 1 | 9.2/10 | 7.5/10 | Zero hallucination, confidence-gated |
| + Phase 2 | 9.5/10 | 8.5/10 | Domain visual language + constraint |
| + Phase 3 | 9.7/10 | 9.0/10 | Cinematic micro-moment sequences |
| + Phase 4 | 9.8/10 | 9.3/10 | Domain-specific narrative structure |
| + Phase 5 | 9.9/10 | 9.6/10 | Cross-video character consistency |
| + Phase 6 | **10/10** | 9.8/10 | Self-improving, fully auditable |

---

## What This System Is Not

**Not a prompt optimizer.** Adding more prompt variations does not improve quality. The system generates better videos by knowing more (FactGraph), deciding more carefully (Decision Ledger + ConstraintEngine), and learning from results (DecisionReplay).

**Not AI-first.** AI is called exactly:
- Haiku × 1: fact extraction per article
- Haiku × 0–3: verification judge (last resort)
- Sonnet × 1: narrative beat fill per production
- Sonnet × 4–6: beat narration (≤ 3 facts context each)
- Haiku × 8–12: quality evaluation per shot (vision)

Everything else — event decomposition, attention flow, camera selection, constraint enforcement, provider selection — is deterministic PHP.

**Not a video tool.** It is a Film Operating System where Kling and Veo are cameras, AFOS is the director's compiler, and the five layers above are the studio intelligence.

---

## Consequences

### Positive
- Decision Ledger makes every rendering decision traceable and auditable
- Confidence Propagation enables automatic human-review gating before video is produced
- ConstraintEngine prevents physically/tonally impossible shots at the architecture level
- AFOS (222 files, 16 planners) is fully leveraged without modification
- Adding a new domain = 1 config file (DomainStyleProfile) + 1 config file (NarrativePattern) + ~5 EventTemplates
- DecisionReplay closes the learning loop: CTR data improves future style decisions automatically

### Negative
- Decision Ledger adds per-shot storage overhead (~2–5 KB per shot × 10 shots/video = ~50 KB/video)
- Confidence Propagation requires calibration — decay rates need empirical tuning from first 100 videos
- ConstraintEngine requires domain expert curation — wrong constraints block valid shots

### Not changing
- AFOS Compiler (ADR-001) — frozen, no modifications
- ProductionEventBus (ADR-004) — reused as-is
- CapabilityResolver (ADR-007) — reused as-is
- DecisionEngine/TournamentDecisionEngine (ADR-010) — used for CRITICAL shots as-is

---

## References

- ADR-001: AFOS Compiler (Layer 3 — frozen)
- ADR-002: FilmOS Unified Model (ProductionBible, WorldState)
- ADR-004: Production Event Bus (ShotDecided, HumanReviewRequired events)
- ADR-006: QualityEngine + PromptIntelligence (Layer 4 scoring)
- ADR-007: CapabilityResolver (Layer 4 provider selection)
- ADR-008: WorldGraph / StateVector (WorldState in Layer 1)
- ADR-009: KnowledgeOS (ConstraintEngine enrichment)
- ADR-010: DecisionEngine tournament loop (Layer 4, CRITICAL shots)
- ADR-011: News Video Pipeline V7 (superseded by this document)
