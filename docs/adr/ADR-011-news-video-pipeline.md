# ADR-011: News Video Pipeline — Graph Orchestration Architecture

**Status:** Draft  
**Date:** 2026-07-07  
**Deciders:** Project Lead  
**Depends on:** ADR-001 (AFOS), ADR-002 (FilmOS Core), ADR-003 (Extended Engines), ADR-004 (Event Bus), ADR-005 (Persistence), ADR-006 (Runtime), ADR-007 (Capability), ADR-008 (WorldGraph), ADR-009 (KnowledgeOS), ADR-010 (DecisionEngine)  
**Domain:** News / Sports / Finance / Politics video automation  
**Phase:** V7 (current foundation) → V8 (target)

---

## Context

### Problem Statement

Automated news video systems follow a naive pipeline pattern:

```
Article → AI summarize → AI script → Render video
```

This produces structurally coherent but factually fragile, visually generic, and emotionally inert videos. The core failure modes:

| Failure Mode | Root Cause |
|---|---|
| AI hallucination | Unconstrained Sonnet generates claims not in source article |
| Camera rigidity | DSL lookup table hardcodes `HOOK→lens=24, TRACKING` regardless of story |
| Visual monotony | No domain visual language — every sport/genre looks the same |
| Missed micro-moments | Events described at statement level, not at observable action level |
| Context blindness | AI sees full article; focus is impossible with 1000 tokens of context |
| No emotional arc | No viewer intent modelling — videos inform but don't engage |
| Silent degradation | Output quality not measured; failures are invisible |

### V7 Design Philosophy

> **AI is a worker. Data and graphs are the center.**

The V7 architecture rejects the pipeline metaphor entirely. Instead:

1. **Atomic Fact Graph** is the single source of truth — AI cannot invent what isn't there
2. **Event Ontology** decomposes events into observable micro-moments — every frame has a purpose
3. **Narrative DAG** is a PHP config — AI fills structure, doesn't design it
4. **Domain Style Profiles** encodes visual language per domain — AI doesn't choose lenses
5. **Graph Orchestration** replaces sequential stages — workers fire when dependencies are ready
6. **Viewer Intent Graph** is the Director's only output — not prompts, not shot lists

This architecture is FilmOS (ADR-001 through ADR-010) running in the news production domain.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                    PRODUCTION GRAPH STORE                           │
│                                                                     │
│  FactGraph          EventGraph        NarrativeGraph                │
│  (SPO triples)      (micro-moments)   (DAG + filled beats)         │
│                                                                     │
│  CharacterGraph     SceneGraph        ViewerIntentGraph             │
│  (Character Bible   (Visual Bible     (AttentionNode[]              │
│   + Motion Bible)    + Style Profile)   + EmotionPoint[])          │
│                                                                     │
│  ShotGraph          AssetGraph        VerificationGraph             │
│  (ShotGoalIR[])     (B-roll + Props)  (claim → verdict)            │
│                                                                     │
│  AnalyticsGraph     QualityGraph                                    │
│  (CTR/engage feed)  (QualityReport[])                              │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ Graph Event Bus (ADR-004)
        ┌──────────────────────┼──────────────────────┐
        ▼                      ▼                      ▼
  [FactExtractor]      [NarrativePlanner]      [Director]
  [EventBuilder]       [BeatContextBuilder]    [VerificationEngine]
  [CharacterProfiler]  [VisualLanguageEngine]  [IntentGraphBuilder]
  [SceneAssembler]     [AFOSCompiler]          [AnalyticsIngester]
        │                      │                      │
        └──────────────────────▼──────────────────────┘
                        Graph Event Bus
                               │
                        [RendererDispatch]
                        [QualityEngine]
                        [DecisionEngine]
```

---

## Production Graph Store

### 1. FactGraph

Atomic, verifiable facts extracted from source articles. The **only** source of truth — no AI call may generate a claim that does not trace to a `Fact` node.

```php
namespace App\Services\AI\NewsVideo\Graphs;

final class Fact
{
    public function __construct(
        public readonly string    $factId,
        public readonly string    $subject,       // "Patrick Mahomes"
        public readonly string    $predicate,     // "threw"
        public readonly string    $object,        // "42-yard touchdown pass"
        public readonly float     $confidence,    // 1.0 = from article, 0.8 = inferred
        public readonly string    $sourceOffset,  // char position in original article
        public readonly FactType  $type,          // STAT, ACTION, QUOTE, CONTEXT, OUTCOME
        public readonly ?string   $timestamp,     // "Q4:02:34" — game time if available
    ) {}
}

enum FactType: string
{
    case STAT       = 'stat';      // "42 yards", "3rd touchdown"
    case ACTION     = 'action';    // "threw", "ran", "caught"
    case QUOTE      = 'quote';     // direct attribution
    case CONTEXT    = 'context';   // "trailing 17-14", "Super Bowl LVIII"
    case OUTCOME    = 'outcome';   // "Chiefs win 24-17"
    case EMOTION    = 'emotion';   // "crowd erupted", "dejected"
}

final class FactGraph
{
    /** @param Fact[] $facts */
    public function __construct(private readonly array $facts) {}

    /** Returns facts about a specific subject. */
    public function about(string $subject): array { ... }

    /** Returns facts of a given type. */
    public function ofType(FactType $type): array { ... }

    /** Returns the N most narratively significant facts (for StorySelector). */
    public function topN(int $n, NarrativeDimension $rankBy): array { ... }

    /** Checks: does a claim string trace to a verified Fact? */
    public function verifies(string $claim): VerificationResult { ... }
}
```

**Design rule:** `FactGraph` contains only what can be traced to source. Confidence < 0.5 facts are tagged `UNVERIFIED` and blocked from AI context.

---

### 2. EventGraph

Decomposes story actions into observable, frame-level micro-moments using an Event Ontology.

```php
final class StoryEvent
{
    public function __construct(
        public readonly string       $eventId,
        public readonly string       $eventClassId,    // from EventOntology
        public readonly string       $actorId,         // characterId
        public readonly array        $participants,    // other characterIds
        public readonly string       $factId,          // → FactGraph.factId (grounding)
        public readonly string       $timestamp,       // game clock or article time ref
        public readonly array        $microMoments,    // MicroMoment[]
        public readonly float        $narrativeWeight, // 0.0–1.0 (how climactic is this)
    ) {}
}

final class MicroMoment
{
    public function __construct(
        public readonly string  $momentId,
        public readonly string  $label,           // "stance_shift", "arm_raise", "release"
        public readonly int     $orderInEvent,    // 1, 2, 3...
        public readonly array   $bodyParts,       // ['dominant_arm', 'hips', 'feet']
        public readonly string  $visualSignal,    // what the camera must capture
        public readonly float   $durationEstSec,  // ~0.5s, ~1.2s, etc.
    ) {}
}
```

#### EventOntology Hierarchy

The ontology (loaded from `config/news_event_ontology.php`) classifies events and provides default micro-moment templates:

```
SportAction
├── ThrowAction
│    ├── NFL_ThrowPass          (stance_shift → step → arm_raise → release → follow_through)
│    │    └── Mahomes_style     (roll_out_right → improvise → off_platform)
│    └── NFL_ThrowSpike         (rush_to_LOS → spike → ref_signal)
├── CatchAction
│    ├── NFL_CatchTD            (route_break → hands_up → secure → celebrate)
│    └── NFL_CatchDrop          (hands_up → ball_contact → drop → reaction)
├── RunAction
│    ├── NFL_RunningBack        (handoff → vision_cut → burst → tackle_or_TD)
│    └── NFL_QB_Scramble        (pocket_collapse → roll_out → run_decision → slide_or_score)
└── CelebrationAction
     ├── NFL_TeamCelebration    (players_converge → jump → chest_bump → crowd_react)
     └── NFL_IndividualCelebration (player_alone → signature_move → crowd_react)

FinancialAction
├── MarketMove
│    ├── StockSurge             (chart_rise → executive_reaction → trading_floor)
│    └── MarketCrash            (chart_drop → panic_sell → press_conference)
└── EarningsEvent
     ├── EarningsBeat           (number_reveal → exec_smile → analyst_reaction)
     └── EarningsMiss           (number_reveal → exec_concern → market_reaction)

PoliticalAction
├── SpeechAction
│    ├── PolicyAnnouncement     (podium_approach → address_start → key_moment → reaction)
│    └── ConcessionSpeech       (podium → acknowledge_loss → graceful_close)
└── VoteAction
     ├── LegislativeVote        (roll_call → tally → reaction → implication)
```

```php
final class EventTemplate
{
    public function __construct(
        public readonly string  $classId,
        public readonly string  $parentClassId,
        public readonly array   $defaultMicroMoments,  // MicroMoment[] — default sequence
        public readonly array   $requiredFacts,        // FactType[] needed to instantiate
        public readonly array   $visualSignals,        // what camera must show per moment
    ) {}
}
```

---

### 3. NarrativeGraph

The story structure — a DAG of Beats with conditional branching based on available FactGraph content.

#### NarrativePatternLibrary

PHP config files (one per domain) that define reusable narrative DAG templates. **No AI designs the structure.**

```php
// config/narrative_patterns/nfl.php
return [
    'patterns' => [
        'nfl_comeback_win' => [
            'beats' => [
                'HOOK'     => ['type' => 'CONFLICT',  'duration' => 15, 'required_facts' => ['deficit_stat']],
                'CONTEXT'  => ['type' => 'SETUP',     'duration' => 10, 'required_facts' => ['game_context']],
                'MOMENT_1' => ['type' => 'CLIMAX',    'duration' => 20, 'required_facts' => ['key_play_action']],
                'MOMENT_2' => ['type' => 'CLIMAX',    'duration' => 15, 'required_facts' => ['second_play_action'], 'optional' => true],
                'RESOLUTION' => ['type' => 'PAYOFF',  'duration' => 10, 'required_facts' => ['outcome']],
                'CODA'     => ['type' => 'REFLECTION', 'duration' => 5, 'required_facts' => ['stat_or_quote'], 'optional' => true],
            ],
            'conditions' => [
                // If no second key play available, skip MOMENT_2
                'MOMENT_2' => 'facts.ofType(ACTION).count() >= 2',
                // If quote available, use it for CODA; else use stat
                'CODA.fact_preference' => 'facts.ofType(QUOTE).count() > 0 ? QUOTE : STAT',
            ],
            'transitions' => [
                'HOOK → CONTEXT'   => ['style' => 'cut'],
                'CONTEXT → MOMENT_1' => ['style' => 'smash_cut'],
                'MOMENT_1 → MOMENT_2' => ['style' => 'cut'],
                'MOMENT_2 → RESOLUTION' => ['style' => 'fade_thru_white'],
                'RESOLUTION → CODA' => ['style' => 'slow_dissolve'],
            ],
        ],
        'nfl_blowout_win' => [ ... ],
        'nfl_upset_loss'  => [ ... ],
    ],
];
```

#### NarrativeDAG (filled)

The live graph — pattern instantiated with actual facts for a specific article:

```php
final class NarrativeBeat
{
    public function __construct(
        public readonly string      $beatId,
        public readonly BeatType    $type,             // HOOK, CONTEXT, CLIMAX, PAYOFF, REFLECTION
        public readonly int         $durationSec,
        public readonly array       $beatFacts,        // Fact[] — 2-3 facts scoped to this beat ONLY
        public readonly array       $eventIds,         // StoryEvent[] IDs this beat visualizes
        public readonly ?string     $nextBeatId,       // null = end
        public readonly ?string     $altNextBeatId,    // conditional branch
        public readonly ?string     $branchCondition,  // PHP expression or null
        public readonly string      $transitionStyle,  // cut, smash_cut, dissolve, etc.
    ) {}
}

final class NarrativeGraph
{
    /** @param NarrativeBeat[] $beats */
    public function __construct(
        private readonly string $patternId,
        private readonly array  $beats,
        private readonly string $rootBeatId,
    ) {}

    /** Traverse beats in order, resolving conditional branches. */
    public function toSequence(FactGraph $facts): array { ... } // NarrativeBeat[]

    /** Total duration in seconds (sum of beat durations). */
    public function totalDurationSec(): int { ... }
}
```

**Key constraint:** Each `NarrativeBeat.beatFacts[]` contains **at most 3 facts**. The AI Director (Sonnet) only receives beat-scoped context — never the full FactPalette.

---

### 4. CharacterGraph

Character Bible + Motion Bible per character, per domain.

```php
final class CharacterProfile
{
    public function __construct(
        public readonly string  $characterId,
        public readonly string  $name,
        public readonly array   $visualIdentifiers, // VisualIdentifier[]
        public readonly array   $motionSignatures,  // MotionSignature[]
        public readonly string  $voiceProfile,      // TTS voice ID
        public readonly array   $domainRoles,       // ['quarterback', 'team_captain']
    ) {}
}

final class VisualIdentifier
{
    public function __construct(
        public readonly string  $type,      // 'jersey_number', 'helmet_color', 'face_feature'
        public readonly string  $value,     // '#15', 'red_with_gold', 'distinctive_beard'
        public readonly float   $prominence, // 0.0–1.0 — how visible must this be in frame
    ) {}
}

final class MotionSignature
{
    public function __construct(
        public readonly string  $actionClass,  // 'NFL_ThrowPass', 'celebration'
        public readonly array   $bodyParts,    // ActionLibrary body decomposition
        public readonly string  $styleNotes,   // "rolls right, throws off back foot"
        public readonly array   $bRollHints,   // asset IDs for cutaway footage
    ) {}
}
```

#### ActionLibrary

Body decomposition for motion — enables AFOS to generate precise MotionContext:

```php
final class ActionFrame
{
    public function __construct(
        public readonly string  $actionClassId,
        public readonly int     $frameOrder,
        public readonly array   $bodyState,  // body_part → state description
        // e.g.: ['feet' => 'plant_forward_left', 'hips' => 'rotate_35deg_right',
        //         'dominant_arm' => 'raise_to_shoulder_then_snap_wrist',
        //         'eyes' => 'downfield_scan']
    ) {}
}
```

---

### 5. SceneGraph

Visual Language per domain. Maps domain + beat type → camera + lighting + composition rules.

```php
final class DomainStyleProfile
{
    public function __construct(
        public readonly string  $domain,         // 'nfl', 'finance', 'politics', 'crime'
        public readonly array   $beatStyleRules, // BeatType → StyleRule
    ) {}
}

final class StyleRule
{
    public function __construct(
        public readonly BeatType    $beatType,
        public readonly array       $preferredLens,     // [85, 135] — telephoto for NFL climax
        public readonly array       $cameraMovement,    // [TRACKING, DOLLY] — not PAN for action
        public readonly string      $lightingMood,      // 'warm_stadium', 'cold_corporate', etc.
        public readonly string      $colorPalette,      // 'high_contrast', 'desaturated', etc.
        public readonly float       $averageCutRhythm,  // 1.5s for climax, 4s for context
        public readonly array       $forbiddenMoves,    // [ZOOM, PAN] — banned in this beat type
    ) {}
}

// Example: NFL domain static config
// 'nfl' => [
//   HOOK    => lens:[24,35],  movement:[TRACKING],  lighting:'stadium_warm', cutRhythm:2.0
//   CLIMAX  => lens:[85,135], movement:[TRACKING,DOLLY], lighting:'high_contrast', cutRhythm:1.2
//   PAYOFF  => lens:[50,85],  movement:[STATIC,SLOW_PUSH], lighting:'golden', cutRhythm:3.5
// ]
```

**Design rule:** `DomainStyleProfile` is a PHP config constant — no AI call touches it. The VisualLanguageEngine validates that all `CameraIR` outputs comply with it.

---

### 6. ViewerIntentGraph

The Director's sole output. Captures where viewer attention should go and the emotional arc.

```php
final class ViewerIntentGraph
{
    public function __construct(
        public readonly array  $attentionChain, // AttentionNode[] in time order
        public readonly array  $emotionCurve,   // EmotionPoint[] — arc across video
        public readonly string $productionId,
        public readonly string $beatId,
    ) {}
}

final class AttentionNode
{
    public function __construct(
        public readonly string  $nodeId,
        public readonly string  $must_show,       // "Mahomes' throwing arm and release point"
        public readonly string  $must_avoid,      // "benched players, referee"
        public readonly float   $timeStartPct,    // 0.0–1.0 of beat duration
        public readonly float   $timeEndPct,
        public readonly float   $priority,        // 0.0–1.0
        public readonly string  $microMomentRef,  // → MicroMoment.momentId
        public readonly ?string $characterRef,    // → CharacterGraph.characterId
    ) {}
}

final class EmotionPoint
{
    public function __construct(
        public readonly float   $timePct,    // 0.0–1.0 of total video
        public readonly string  $emotion,    // 'anticipation', 'tension', 'release', 'triumph'
        public readonly float   $intensity,  // 0.0–1.0
        public readonly string  $trigger,    // what causes this emotion shift
    ) {}
}
```

**Connection to AFOS (ADR-001):**

`AttentionNode.must_show` and `.must_avoid` map directly to `ShotGoalIR.viewerShouldNotice[]` and `.viewerShouldIgnore[]` — these fields already exist in the frozen AFOS compiler. The Director's output feeds AFOS without modifying it.

---

### 7. ShotGraph

The compiled shot list — output of AFOS per beat.

```php
final class ShotNode
{
    public function __construct(
        public readonly string   $shotId,
        public readonly string   $beatId,
        public readonly int      $orderInBeat,
        public readonly ShotGoalIR    $goalIR,      // AFOS input
        public readonly CompositionIR $compositionIR,
        public readonly CameraIR      $cameraIR,
        public readonly PromptIR      $promptIR,
        public readonly ?string  $videoUrl,          // null until rendered
        public readonly ?QualityReport $quality,     // null until evaluated
        public readonly ShotStatus    $status,
    ) {}
}

enum ShotStatus: string
{
    case PLANNED    = 'planned';
    case COMPILING  = 'compiling';    // AFOS running
    case QUEUED     = 'queued';       // ready for renderer
    case RENDERING  = 'rendering';
    case EVALUATING = 'evaluating';   // QualityEngine running
    case ACCEPTED   = 'accepted';
    case FAILED     = 'failed';
}
```

---

### 8. VerificationGraph

Audit trail of every claim checked against `FactGraph`.

```php
final class VerificationResult
{
    public function __construct(
        public readonly string           $claimText,
        public readonly VerificationTier $tier,       // PHP_EXACT, PHP_NLP, AI_JUDGE
        public readonly bool             $verified,
        public readonly ?string          $matchedFactId,
        public readonly float            $confidence,
        public readonly string           $explanation,
    ) {}
}

enum VerificationTier: string
{
    case PHP_EXACT  = 'php_exact';   // entity match, number match (free, instant)
    case PHP_NLP    = 'php_nlp';     // synonym, paraphrase (free, near-instant)
    case AI_JUDGE   = 'ai_judge';    // Haiku, last resort only (~$0.0001/claim)
}
```

**Layered verification strategy:**
1. `ClaimExtractor` (PHP regex + NLP) — extract verifiable claims from beat narration
2. `KnowledgeMatcher` (PHP) — exact entity/number match against `FactGraph`
3. `SemanticMatcher` (PHP NLP) — paraphrase/synonym match
4. `AIJudge` (Haiku) — only if PHP tiers return `UNCERTAIN`

**Block rule:** Any beat narration containing an `UNVERIFIED` claim is rejected before rendering.

---

### 9. AnalyticsGraph

CTR, watch time, and engagement data feeding back to `DomainStyleProfile` and `NarrativePatternLibrary`.

```php
final class VisualLanguageRecord
{
    public function __construct(
        public readonly string  $recordId,
        public readonly string  $domain,
        public readonly string  $beatType,
        public readonly int     $lensUsed,          // 85
        public readonly string  $movementUsed,      // 'TRACKING'
        public readonly string  $lightingMood,
        public readonly float   $ctr,               // click-through rate
        public readonly float   $watchPct,          // average watch completion
        public readonly float   $engagementRate,
        public readonly \DateTimeImmutable $recordedAt,
    ) {}
}
```

Aggregated weekly by `AnalyticsIngester` → updates `DomainStyleProfile.beatStyleRules` scores. Style rules with CTR < domain average are flagged for review.

---

## Worker Definitions

Each worker reads specific graph scopes, writes to specific graph scopes, and fires via the Graph Event Bus.

| Worker | Trigger Event | Reads | Writes | AI Used? |
|--------|--------------|-------|--------|----------|
| `FactExtractor` | `ArticleReceived` | Article text | `FactGraph` | Haiku (SPO extraction) |
| `EventBuilder` | `FactGraphReady` | `FactGraph`, `EventOntology` | `EventGraph` | None (PHP template matching) |
| `CharacterProfiler` | `FactGraphReady` | `FactGraph`, `CharacterBible` | `CharacterGraph` | None (PHP lookup) |
| `NarrativePlanner` | `EventGraphReady` | `EventGraph`, `NarrativePatternLibrary` | `NarrativeGraph` | Sonnet (beat fill only) |
| `BeatContextBuilder` | `NarrativeGraphReady` | `NarrativeGraph`, `FactGraph` | `BeatContext[]` | None (PHP graph traversal) |
| `Director` | `BeatContextReady` | `BeatContext`, `CharacterGraph` | `ViewerIntentGraph` | Sonnet (intent only) |
| `IntentGraphBuilder` | `ViewerIntentGraphReady` | `ViewerIntentGraph`, `SceneGraph`, `CharacterGraph` | `ShotGoalIR[]` | None (PHP mapping) |
| `AFOSCompiler` | `ShotGoalIRReady` | `ShotGoalIR`, `RenderContext` | `ShotGraph` | None (deterministic compiler) |
| `VisualLanguageValidator` | `ShotGraphReady` | `ShotGraph`, `SceneGraph.DomainStyleProfile` | Validation verdict | None (PHP rule check) |
| `RendererDispatch` | `ShotValidated` | `ShotGraph` | `ShotGraph.videoUrl` | None (API call) |
| `QualityEngine` | `ShotRendered` | Rendered video, `ShotGraph` | `QualityGraph` | Haiku (vision eval) |
| `VerificationEngine` | `BeatNarrationReady` | Beat narration, `FactGraph` | `VerificationGraph` | Haiku (last resort) |
| `AnalyticsIngester` | `VideoPublished` | Analytics platform | `AnalyticsGraph` | None |

**AI discipline:**
- **Haiku**: FactExtractor (SPO), QualityEngine (vision), VerificationEngine (last resort)
- **Sonnet**: NarrativePlanner (beat fill), Director (viewer intent)
- **No AI**: EventBuilder, CharacterProfiler, BeatContextBuilder, IntentGraphBuilder, AFOSCompiler, VisualLanguageValidator, RendererDispatch, AnalyticsIngester

---

## Graph Event Bus

Reuses ADR-004 `ProductionEventBus` with news-domain events:

```php
// News-domain events (all implement ProductionEvent from ADR-004)

final class ArticleReceived implements ProductionEvent { ... }
final class FactGraphReady implements ProductionEvent { ... }
final class EventGraphReady implements ProductionEvent { ... }
final class NarrativeGraphReady implements ProductionEvent { ... }
final class BeatContextReady implements ProductionEvent
{
    public string $beatId;  // per-beat firing
}
final class ViewerIntentGraphReady implements ProductionEvent
{
    public string $beatId;
}
final class ShotGoalIRReady implements ProductionEvent
{
    public string $shotId;  // per-shot firing — enables parallelism
}
final class ShotValidated implements ProductionEvent { ... }
final class ShotRendered implements ProductionEvent { ... }
final class ShotDecided implements ProductionEvent { ... }  // from ADR-010
final class VideoPublished implements ProductionEvent { ... }
```

**Parallelism:** `ShotGoalIRReady` fires per-shot. Shots within a beat are independent and render in parallel (up to `DecisionBudget.maxParallelRenders` — ADR-010).

---

## StorySelector (FactPalette)

The context gateway. Reduces the full `FactGraph` (120+ facts) to a curated palette before any AI sees it.

```php
final class StorySelector
{
    /**
     * Selects the N most narratively significant facts for the full palette.
     * This palette is then further scoped per beat to 2-3 facts.
     *
     * @return Fact[]  max 12 facts
     */
    public function selectPalette(FactGraph $facts, string $narrativePattern): array
    {
        // 1. Require: at least one OUTCOME fact
        // 2. Require: at least one key ACTION fact (highest narrativeWeight)
        // 3. Include: top STAT facts (by viewer engagement score from AnalyticsGraph)
        // 4. Include: QUOTE facts if available (highest emotional impact)
        // 5. Fill to 12 with highest-confidence CONTEXT facts
        // 6. Sort by narrative sequence (chronological within pattern)
    }
}
```

**Rule:** Sonnet receives at most **3 facts per beat** from `BeatContextBuilder`. The full palette is never passed to any AI.

---

## IntentGraph → AFOS Bridge

Maps `ViewerIntentGraph` to `ShotGoalIR` without a DSL lookup table.

```php
final class IntentGraphBuilder
{
    public function buildShotGoals(
        ViewerIntentGraph $intent,
        CharacterGraph    $characters,
        DomainStyleProfile $style,
        NarrativeBeat     $beat,
    ): array  // ShotGoalIR[]
    {
        $shots = [];

        foreach ($intent->attentionChain as $node) {
            $microMoment = $this->eventGraph->getMoment($node->microMomentRef);

            $shots[] = new ShotGoalIR(
                // Director's intent maps to AFOS-native fields:
                viewerShouldNotice: [$node->must_show],
                viewerShouldIgnore: [$node->must_avoid],

                // Style comes from DomainStyleProfile, not hardcoded:
                preferredLens:      $style->getRule($beat->type)->preferredLens,
                cameraMovement:     $style->getRule($beat->type)->cameraMovement,

                // Character comes from CharacterGraph:
                characters:         $this->resolveCharacters($node->characterRef, $characters),

                // Motion from ActionLibrary via MicroMoment:
                motionHint:         $microMoment->bodyParts,

                // Emotional weight:
                emotionWeight:      $this->resolveEmotion($node, $intent->emotionCurve),

                // Beat context:
                beatType:           $beat->type->value,
                durationSec:        $microMoment->durationEstSec,
            );
        }

        return $shots;
    }
}
```

**No DSL lookup table.** Camera parameters flow from `DomainStyleProfile` (data) and `ViewerIntentGraph` (Director intent) — not from hardcoded `PURPOSE_DSL` maps.

---

## AI Call Discipline

Total AI budget per 60-second news video:

| Worker | Model | Calls | Avg Tokens In | Cost Est. |
|--------|-------|-------|---------------|-----------|
| `FactExtractor` | Haiku | 1 | ~2,000 | $0.0005 |
| `NarrativePlanner` | Sonnet | 1 | ~800 (12 facts max) | $0.008 |
| `Director` (per beat) | Sonnet | 4–6 | ~300 each | $0.015 |
| `QualityEngine` (per shot) | Haiku vision | 8–12 | ~500 each | $0.003 |
| `VerificationEngine` | Haiku | 0–3 | ~400 each | $0.0003 |
| **Total** | | | | **~$0.027** |

AI rendering (Kling/Veo) is separate from orchestration cost (~$0.40–$0.50 for 60s video).

**Estimated total per video: ~$0.50–$0.55** at V7 quality.

---

## Connection to FilmOS ADR Architecture

V7 News Pipeline is the FilmOS architecture applied to the news production domain:

| V7 Component | FilmOS Equivalent | ADR |
|---|---|---|
| `FactGraph` | `ProductionBible.WorldModule` (source of truth) | ADR-002 |
| `EventGraph` + `EventOntology` | `KnowledgeOS` inference + `WorldGraph` events | ADR-008, ADR-009 |
| `NarrativePatternLibrary` | `SceneGraph v2` pattern templates | ADR-002 |
| `NarrativeGraph` (filled DAG) | `SceneGraph` with `ShotNode` sequence | ADR-002 |
| `CharacterGraph` | `CharacterModule` + `MotionLibrary` (ADR-003) | ADR-002, ADR-003 |
| `DomainStyleProfile` | `StyleModule` + 7 Bibles | ADR-002, ADR-003 |
| `ViewerIntentGraph` | `DirectorOS` output (ADR-006) | ADR-006 |
| `IntentGraphBuilder` | `PlanningContextBuilder` → `ShotGoalIR` | ADR-002 |
| `AFOS Compiler` | AFOS Compiler (frozen) | ADR-001 |
| `VisualLanguageValidator` | `VisualLanguageEngine.validate(CameraIR)` | ADR-003 |
| `DecisionEngine` (per shot) | `TournamentDecisionEngine` | ADR-010 |
| `CapabilityResolver` | `CostAwareCapabilityResolver` | ADR-007 |
| `VerificationGraph` | `ConstraintEngine` (validates before AI calls) | ADR-002 |
| `AnalyticsGraph` → `DomainStyleProfile` | `PromptIntelligence` learning loop | ADR-006 |
| `Graph Event Bus` | `ProductionEventBus` (ADR-004) | ADR-004 |

---

## V7 → V8 Roadmap

V8 = News Video Pipeline running on complete FilmOS + EditingOS + VisualOS.

### Missing Subsystems for V8

| V8 Subsystem | Maps To | ADR | Current V7 Gap |
|---|---|---|---|
| **Film Language Engine** | `VisualLanguageEngine` (Phase C) | ADR-003 | V7 uses `DomainStyleProfile` rules; V8 adds grammar validation (7 Bibles) |
| **Editing Engine** | `EditingOS` (Phase F) | ADR-003 Amendment F | V7 has `transitionStyle` in `NarrativeBeat`; V8 adds `RhythmPlanner` + `BeatAligner` + `EDL` export |
| **Emotion Engine** | `EmotionCurve` in `DirectorOS` (Phase G) | ADR-006 | V7 has `EmotionPoint[]`; V8 adds `SemanticGraph` conflict/payoff/foreshadow arcs |
| **Visual Intelligence** | `VisualMemory` (Phase E) + `PromptIntelligence` | ADR-003, ADR-006 | V7 has `VisualLanguageRecord`; V8 adds `AppearanceMemory` + `pgvector` embeddings |

### V8 Phase Roadmap

```
V7 (NOW)      Graph Orchestration (this ADR)
              FactGraph + EventGraph + NarrativeGraph + CharacterGraph
              ViewerIntentGraph + IntentGraphBuilder → AFOS → DecisionEngine

V8.1          Film Language Engine
              → Replace DomainStyleProfile rules with 7-Bible VisualGrammar
              → VisualLanguageEngine.validate(CameraIR) active for news domain
              → ADR-003 Phase C implementation

V8.2          Editing Engine
              → NarrativeBeat.transitionStyle → RhythmPlanner → EDL
              → BeatAligner (music sync for sports highlights)
              → Export: DaVinci Resolve / Premiere timeline
              → ADR-003 Amendment F Phase F implementation

V8.3          Emotion Engine
              → ViewerIntentGraph.emotionCurve → SemanticGraph (conflict/payoff)
              → DirectorOS autonomously adjusts beat intensity based on conflict arcs
              → ADR-006 Phase G implementation

V8.4          Visual Intelligence
              → VisualLanguageRecord → AppearanceMemory (pgvector)
              → Semantic search: "find shots similar to this celebration style"
              → PromptIntelligence learns domain-specific prompt patterns
              → ADR-003 Amendment E + ADR-006 PromptIntelligence
```

---

## Directory Structure

```
app/Services/AI/NewsVideo/
├── Graphs/
│   ├── FactGraph/
│   │   ├── FactGraph.php
│   │   ├── Fact.php
│   │   ├── Enums/
│   │   │   └── FactType.php
│   │   └── StorySelector.php
│   ├── EventGraph/
│   │   ├── EventGraph.php
│   │   ├── StoryEvent.php
│   │   ├── MicroMoment.php
│   │   ├── EventTemplate.php
│   │   └── EventOntology.php
│   ├── NarrativeGraph/
│   │   ├── NarrativeGraph.php
│   │   ├── NarrativeBeat.php
│   │   ├── NarrativePatternLibrary.php
│   │   └── Enums/
│   │       └── BeatType.php
│   ├── CharacterGraph/
│   │   ├── CharacterProfile.php
│   │   ├── VisualIdentifier.php
│   │   ├── MotionSignature.php
│   │   └── ActionLibrary.php
│   ├── SceneGraph/
│   │   ├── DomainStyleProfile.php
│   │   └── StyleRule.php
│   ├── ViewerIntentGraph/
│   │   ├── ViewerIntentGraph.php
│   │   ├── AttentionNode.php
│   │   └── EmotionPoint.php
│   ├── ShotGraph/
│   │   ├── ShotNode.php
│   │   └── Enums/
│   │       └── ShotStatus.php
│   ├── VerificationGraph/
│   │   ├── VerificationResult.php
│   │   └── Enums/
│   │       └── VerificationTier.php
│   └── AnalyticsGraph/
│       └── VisualLanguageRecord.php
├── Workers/
│   ├── FactExtractor.php
│   ├── EventBuilder.php
│   ├── CharacterProfiler.php
│   ├── NarrativePlanner.php
│   ├── BeatContextBuilder.php
│   ├── Director.php
│   ├── IntentGraphBuilder.php
│   ├── VisualLanguageValidator.php
│   ├── VerificationEngine/
│   │   ├── ClaimExtractor.php
│   │   ├── KnowledgeMatcher.php
│   │   ├── SemanticMatcher.php
│   │   └── AIJudge.php
│   └── AnalyticsIngester.php
├── Events/
│   ├── ArticleReceived.php
│   ├── FactGraphReady.php
│   ├── EventGraphReady.php
│   ├── NarrativeGraphReady.php
│   ├── BeatContextReady.php
│   ├── ViewerIntentGraphReady.php
│   ├── ShotGoalIRReady.php
│   ├── ShotValidated.php
│   └── VideoPublished.php
└── ProductionGraphStore.php   (facade: single access point to all graphs)

config/
├── narrative_patterns/
│   ├── nfl.php
│   ├── finance.php
│   ├── politics.php
│   └── crime.php
└── news_event_ontology.php
```

---

## Consequences

### Positive
- **Zero hallucination**: Every narration claim traces to a `Fact` in `FactGraph`
- **Visual consistency**: `DomainStyleProfile` enforces NFL/finance/politics visual language — no random lenses
- **Parallelism**: Per-shot event firing allows shots within a beat to render simultaneously
- **Scalability**: Graph orchestration with no blocking sequential stages — 10,000 videos/day feasible
- **Learning loop**: `AnalyticsGraph` → `DomainStyleProfile` score updates → gradually improving style choices
- **AFOS unchanged**: `ShotGoalIR` fields already support `viewerShouldNotice/Ignore`; no compiler modification
- **AI cost contained**: Sonnet called ≤7 times total per video; Haiku handles high-volume low-stakes tasks

### Negative
- **Ontology maintenance**: `EventOntology` + `NarrativePatternLibrary` must be curated per domain; wrong templates produce wrong videos
- **Cold start**: New domain (e.g., tennis) requires: EventOntology classes + NarrativePatterns + DomainStyleProfile + CharacterBible before first video
- **Graph construction overhead**: `FactGraph → EventGraph → NarrativeGraph` adds ~1–2s latency before rendering starts (mitigated: parallelizable with article crawl)
- **VerificationEngine false positives**: Layered verifier may reject valid paraphrases if PHP NLP coverage is thin (mitigated: AI Judge fallback)

### Not changing
- **AFOS Compiler** — frozen (ADR-001); `ShotGoalIR` fields already sufficient
- **Kling/Veo API integrations** — handled by `CapabilityResolver` (ADR-007)
- **FilmOS ADR-001 through ADR-010** — this ADR is an application layer, not a replacement

---

## References

- ADR-001: AFOS Compiler (`ShotGoalIR.viewerShouldNotice/Ignore` fields consumed by `IntentGraphBuilder`)
- ADR-002: ProductionBible model (FactGraph → WorldModule analogue)
- ADR-003: StyleBibles (DomainStyleProfile is the news-domain equivalent of 7 Bibles)
- ADR-004: Production Event Bus (Graph Event Bus reuses same infrastructure)
- ADR-006: DirectorOS (ViewerIntentGraph = DirectorOS output in news domain)
- ADR-007: CapabilityResolver (shot dispatch to Kling/Veo/Runway)
- ADR-008: WorldGraph (EventGraph + CharacterGraph compose the news WorldGraph)
- ADR-009: KnowledgeOS (EventOntology hierarchy mirrors OntologyClass hierarchy)
- ADR-010: DecisionEngine (per-shot quality optimization, budget by beat priority)
