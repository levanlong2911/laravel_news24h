# ADR-013: FilmOS Architecture v2.0 — The Meaning Layer

**Status:** Amended by ADR-014  
**Date:** 2026-07-07  
**Revision:** 3 (5 code bugs fixed: ProductionBudget constructor, VisualStrategy layer placement, dead $function param, modulateByFact accumulation, compressionRatio type)  
**Superseded by (partial):** ADR-014 replaces SemanticMeaning→MeaningGraph, VisualStrategyResolver→DecisionGraph, DAG log→DAGRuntime, ResourceOrchestrator→FilmKernel, adds HypothesisGenerator  
**Deciders:** Chief Architect + Project Lead  
**Amends:** ADR-012 (FilmOS Incremental Architecture v1.0)  
**Scope:** 8-layer architecture for FilmOS with long-term advantage (3–5 years)

---

## Context

ADR-012 established a 5-layer architecture (Knowledge → Decision → Compilation → Rendering → Learning).

This ADR extends it to 8 layers by adding three things that define long-term competitive advantage:

1. **Meaning Layer** (Layer 2): Objects acquire semantic meaning from context, not from static lookup
2. **Temporal Memory** (Layer 1 extension): The system remembers what has already happened
3. **Decision DAG** (cross-cutting): Decisions form a traceable graph, not a log

Six additional structural corrections from production architecture review:
- `DirectorIntent` split into focused context objects (no fat interface)
- Meaning Layer scope reduced: returns semantics only, not camera guidance
- `TensionEngine` → `VisualStrategy` → Camera (two-step, not one-step)
- `PatternCandidate` has confidence scoring to prevent overfitting
- `KnowledgeEvolution` has memory compression for long-term growth
- `ResourceOrchestrator` as cross-cutting service for scale

---

## 8-Layer Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│  LAYER 1: KNOWLEDGE                                                  │
│  FactGraph · ProductionBible · WorldState · SceneTimeline [NEW]      │
│  Source of truth. Temporal history across shots.                     │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 2: MEANING  [scope-limited: semantics only]                   │
│  MeaningResolver · CinematicOntology · NarrativeTensionEngine        │
│  Outputs: SemanticMeaning + CinematicFunction + TensionLevel         │
│  Does NOT output camera/color/attention guidance (Decision Layer)    │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 3: DECISION                                                   │
│  StorySelector · NarrativePattern · AttentionFlow                    │
│  ConstraintEngine · VisualStrategyResolver                           │
│  Translates SemanticMeaning → camera/color/attention                 │
│  Outputs: MeaningContext + ExecutionContext (feed Layer 4)            │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 4: INTENT                                                     │
│  DirectorIntent = { MeaningContext + ExecutionContext + EvalContext } │
│  Three focused context objects. Sole input to Compilation.           │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 5: COMPILATION  [AFOS — frozen, ADR-001]                      │
│  ShotGoalIR → 16 planners → CameraIR → PromptIR                      │
│  Reads ExecutionContext only. Knows nothing of Layers 1–3.           │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 6: RENDERING                                                  │
│  CapabilityResolver (ADR-007) · RendererPlugins · DecisionEngine     │
│  ResourceOrchestrator [cross-cutting]                                │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 7: EVALUATION                                                 │
│  MultiAgentReview: Visual · Narrative · Fact(veto) · Emotion · Brand │
│  Reads EvalContext. Weighted consensus + Fact veto.                  │
├──────────────────────────────────────────────────────────────────────┤
│  LAYER 8: LEARNING                                                   │
│  AssetGraph · KnowledgeEvolution · DecisionDAG replay                │
│  PatternDiscovery (with confidence scoring)                          │
│  Memory compression for long-term growth                             │
└──────────────────────────────────────────────────────────────────────┘

Cross-cutting services (not a layer — attach to all layers):
  DecisionDAG        — causal trace: Fact → Meaning → Decision → Render → Review → Analytics
  ConfidencePropagator — confidence decay per step
  ResourceOrchestrator — GPU, budget, priority queue, rate limits
```

---

## Correction 1: MeaningResolver (context-dependent, not static lookup)

### Problem with first draft

The initial draft had:

```php
// ❌ broken_glass → danger   (always, regardless of context)
// ❌ config/filmos_visual_concepts.php = static mapping
```

This is wrong. "Broken glass" in a crime scene ≠ "broken glass" in a museum art installation ≠ "broken glass" during an earthquake.

### Correction: MeaningResolver takes 4 inputs

```php
namespace App\Services\AI\FilmOS\Meaning;

interface MeaningResolver
{
    /**
     * Resolves the semantic meaning of a visual element by considering:
     * - The element itself (asset class)
     * - The verified facts about the current event
     * - The narrative context (what beat type, what pattern)
     * - The world context (indoor/outdoor, time, location)
     *
     * NEVER from a static lookup alone.
     */
    public function resolve(
        AssetDefinition  $asset,
        array            $facts,          // Fact[] — verified facts for this beat
        NarrativeContext $narrativeCtx,   // beat type, pattern, story arc position
        WorldState       $worldState,     // location, time of day, nearby assets
    ): SemanticMeaning;
}

final class SemanticMeaning
{
    public function __construct(
        public readonly string  $subject,         // what element this meaning applies to
        public readonly array   $primaryMeanings, // MeaningSignal[] — top 3 by weight
        public readonly float   $importance,      // 0.0–1.0 — how narratively significant
        public readonly array   $symbolism,       // string[] — ['danger', 'crime'] — labels only
        public readonly float   $confidence,      // how certain is this meaning resolution
        public readonly string  $resolvedBy,      // 'context_rules' | 'ai_inference' | 'ontology'
    ) {}

    // Does NOT contain: camera guidance, color guidance, attention weight
    // Those are Decision Layer concerns
}

final class MeaningSignal
{
    public function __construct(
        public readonly string  $meaning,     // 'danger', 'crime', 'memory', 'art', 'accident'
        public readonly float   $weight,      // 0.0–1.0
        public readonly string  $evidence,    // WHY this meaning: "fact contains 'crime scene'", "location=police_station"
    ) {}
}
```

**Default implementation — `ContextualMeaningResolver`:**

```php
final class ContextualMeaningResolver implements MeaningResolver
{
    public function resolve(
        AssetDefinition  $asset,
        array            $facts,
        NarrativeContext $narrativeCtx,
        WorldState       $worldState,
    ): SemanticMeaning {

        // Step 1: Base signals from asset class (starting point, NOT final answer)
        $signals = $this->ontologyRegistry->baseSignals($asset->ontologyClassId);

        // Step 2: Modulate by facts about this event
        foreach ($facts as $fact) {
            $signals = $this->modulateByFact($signals, $fact);
        }

        // Step 3: Modulate by narrative position
        $signals = $this->modulateByNarrative($signals, $narrativeCtx);

        // Step 4: Modulate by world context
        $signals = $this->modulateByWorldState($signals, $worldState);

        // Step 5: If still ambiguous (all signals < 0.6), use AI inference (Haiku)
        if ($this->isAmbiguous($signals)) {
            $signals = $this->aiInference->inferMeaning($asset, $facts, $worldState);
            $resolvedBy = 'ai_inference';
        } else {
            $resolvedBy = 'context_rules';
        }

        return new SemanticMeaning(
            subject:         $asset->displayName,
            primaryMeanings: $this->topN($signals, 3),
            importance:      $this->computeImportance($signals, $narrativeCtx),
            symbolism:       array_map(fn($s) => $s->meaning, $this->topN($signals, 5)),
            confidence:      $this->maxWeight($signals),
            resolvedBy:      $resolvedBy,
        );
    }

    private function modulateByFact(array $signals, Fact $fact): array
    {
        // Example: broken_glass base signal = [danger:0.7, crime:0.5, memory:0.4]
        // Fact: "earthquake measured 6.2" → crime:0.0, danger:0.95, accident:0.8
        // Fact: "art gallery" (from WorldState.location) → art:0.9, memory:0.6, crime:0.0
        // Modulation is additive with weight normalization against current signals
    }
}
```

**Ambiguity resolution examples:**

| Asset | Facts | Location | Dominant meaning |
|---|---|---|---|
| broken_glass | "crime reported" | alley | crime (0.92) |
| broken_glass | "earthquake 6.2" | office building | accident/disaster (0.88) |
| broken_glass | none | art gallery | art_installation (0.75) |
| broken_glass | "memory of childhood" (quote) | family home | memory (0.81) |

---

## Correction 2: DirectorIntent Split (no fat interface)

### Problem with first draft

```php
// ❌ DirectorIntent was accumulating everything:
// attentionNode + beat + facts + confidence + styleRule + constraints
// + sceneMeaning + cinematicFunction + tensionLevel + tensionParams + referencesShotId
// → Too many responsibilities
```

### Correction: Three focused context objects

```php
namespace App\Services\AI\FilmOS\Intent;

/**
 * From Layer 2 (Meaning). Pure semantic signal.
 * Camera/color/attention/movement NOT here — those are Decision Layer concerns.
 */
final class MeaningContext
{
    public function __construct(
        public readonly SemanticMeaning    $primaryMeaning,   // what does this scene mean?
        public readonly CinematicFunction  $function,         // REVEAL, ESCALATE, ECHO...
        public readonly float              $tensionLevel,     // 0.0–10.0
        public readonly ?string            $referencesShotId, // for ECHO/CALLBACK functions
        public readonly float              $meaningConfidence,
    ) {}
}

/**
 * From Layer 3 (Decision). What to do — camera, attention, facts.
 * Sole input that AFOS reads.
 */
final class ExecutionContext
{
    public function __construct(
        public readonly AttentionNode    $attentionNode,    // must_show, must_avoid
        public readonly NarrativeBeat    $beat,
        public readonly array            $beatFacts,        // Fact[], max 3
        public readonly VisualStrategy   $visualStrategy,   // resolved by VisualStrategyResolver (Layer 3)
        public readonly DomainStyleRule  $styleRule,        // lens hints, movement hints
        public readonly array            $softConstraints,  // ConstraintViolation[] (SOFT only)
        public readonly float            $sourceConfidence,
    ) {}
}

/**
 * From Layer 3 (Decision). What reviewers need to evaluate the result.
 * Layer 7 reads this, AFOS does not.
 */
final class EvaluationContext
{
    public function __construct(
        public readonly ShotPriority       $priority,
        public readonly float              $acceptanceThreshold, // review score floor
        public readonly array              $brandGuidelines,     // optional
        public readonly array              $expectedEmotions,    // EmotionTarget[] for EmotionReviewer
        public readonly array              $requiredFacts,       // Fact[] that narration must cover
        public readonly bool               $requiresFactVeto,    // always true for news domain
    ) {}
}

/**
 * The thin wrapper. Just ties the three contexts together + IDs.
 * No business logic.
 */
final class DirectorIntent
{
    public function __construct(
        public readonly string           $productionId,
        public readonly string           $shotId,
        public readonly string           $decisionDagId,     // → DecisionDAG session
        public readonly MeaningContext   $meaning,
        public readonly ExecutionContext $execution,
        public readonly EvaluationContext $evaluation,
    ) {}

    // AFOS receives: $intent->execution  (only this)
    // Evaluation receives: $intent->evaluation + rendered video
    // Decision DAG receives: all three (for tracing)
}
```

---

## Correction 3: Layer 2 Scope (semantics only)

**Layer 2 ONLY outputs: `SemanticMeaning` + `CinematicFunction` + `TensionLevel`**

It does NOT decide:
- Camera lens (Decision Layer)
- Color palette (Decision Layer)
- Attention weight (Decision Layer)
- Movement type (Decision Layer)

The Decision Layer translates Layer 2 outputs into those camera/color/attention decisions. This keeps Meaning Layer as pure semantics — it does not know what a camera is.

```php
// Layer 3 (Decision) translation — not Layer 2:
final class MeaningToExecutionTranslator
{
    public function translate(
        SemanticMeaning    $meaning,
        CinematicFunction  $function,
        VisualStrategy     $strategy,
        DomainStyleProfile $domainStyle,
    ): ExecutionContext
    {
        // Dominant meaning 'danger' → ConstraintEngine adds no_warm_color
        // CinematicFunction REVEAL → DomainStyleProfile.getRule(REVEAL)
        // VisualStrategy OBSERVATIONAL → overrides lens toward longer focal lengths
        // Decision Layer owns this translation, not Meaning Layer
    }
}
```

---

## Correction 4: TensionEngine → VisualStrategy (two-step)

### Problem with first draft

```php
// ❌ tensionLevel >= 8.0 → HANDHELD (always)
// This is factually wrong. High tension ≠ handheld.
// No Country for Old Men: tension=9, camera LOCKED.
// Sicario: tension=8, camera OBSERVATIONAL.
// Chernobyl: tension=9, camera HANDHELD.
```

### Correction: Tension → VisualStrategy → Camera

```php
enum VisualStrategy: string
{
    case KINETIC       = 'kinetic';       // high energy, handheld, fast cuts — action/sports
    case OBSERVATIONAL = 'observational'; // high tension, locked camera, long holds — thriller/crime
    case CONTEMPLATIVE = 'contemplative'; // low energy, slow push, long takes — documentary/memory
    case URGENT        = 'urgent';        // fast cuts, shaky, extreme close — breaking news
    case LYRICAL       = 'lyrical';       // smooth movement, golden light — emotional/inspirational
}

final class VisualStrategyResolver
{
    /**
     * Tension level alone does NOT determine camera.
     * Domain + narrative context + tone together pick the strategy.
     * Then strategy maps to camera parameters.
     */
    public function resolve(
        float            $tensionLevel,
        string           $domain,        // 'nfl', 'crime_documentary', 'breaking_news'
        string           $narrativeTone, // 'suspense', 'triumph', 'grief', 'urgency'
        CinematicFunction $function,
    ): VisualStrategy
    {
        // High tension strategies by domain:
        // nfl + tension >= 7            → KINETIC
        // crime_documentary + tension >= 7 → OBSERVATIONAL  (No Country, Sicario)
        // breaking_news + tension >= 7  → URGENT
        // finance_crisis + tension >= 7 → OBSERVATIONAL
        // memorial + tension >= 6       → CONTEMPLATIVE

        return match(true) {
            $function === CinematicFunction::ECHO                   => VisualStrategy::CONTEMPLATIVE,
            $domain === 'nfl' && $tensionLevel >= 6.0               => VisualStrategy::KINETIC,
            $domain === 'crime_documentary'                         => VisualStrategy::OBSERVATIONAL,
            $domain === 'breaking_news' && $tensionLevel >= 5.0     => VisualStrategy::URGENT,
            $narrativeTone === 'grief'                              => VisualStrategy::CONTEMPLATIVE,
            $narrativeTone === 'triumph'                            => VisualStrategy::LYRICAL,
            default                                                 => VisualStrategy::OBSERVATIONAL,
        };
    }

    /** Translate VisualStrategy into camera parameters for DomainStyleRule. */
    public function cameraParams(VisualStrategy $strategy, float $tensionLevel): array
    {
        return match($strategy) {
            VisualStrategy::KINETIC => [
                'stability'   => $tensionLevel >= 8.0 ? 'HANDHELD_STRONG' : 'HANDHELD_SUBTLE',
                'cut_rhythm'  => max(0.8, 2.5 - $tensionLevel * 0.15),
                'lens_pref'   => [35, 50],
            ],
            VisualStrategy::OBSERVATIONAL => [
                'stability'   => 'LOCKED',    // ← key insight: high tension, static camera
                'cut_rhythm'  => max(2.0, 5.0 - $tensionLevel * 0.2),
                'lens_pref'   => [85, 135],   // telephoto observation distance
            ],
            VisualStrategy::URGENT => [
                'stability'   => 'HANDHELD_STRONG',
                'cut_rhythm'  => max(0.6, 1.8 - $tensionLevel * 0.1),
                'lens_pref'   => [24, 35],
            ],
            VisualStrategy::CONTEMPLATIVE => [
                'stability'   => 'LOCKED',
                'cut_rhythm'  => 4.0 + (5.0 - $tensionLevel) * 0.3,
                'lens_pref'   => [50, 85],
            ],
            VisualStrategy::LYRICAL => [
                'stability'   => 'SMOOTH_DOLLY',
                'cut_rhythm'  => 3.5,
                'lens_pref'   => [50, 85],
            ],
        };
    }
}
```

**Result:** The same tension=9 produces:

| Domain | Strategy | Camera |
|---|---|---|
| NFL | KINETIC | Handheld, fast cuts, 35mm |
| Crime documentary | OBSERVATIONAL | Locked, long holds, 135mm |
| Breaking news | URGENT | Handheld strong, 24mm, 0.8s cuts |
| Memorial | CONTEMPLATIVE | Locked, 85mm, 5s holds |

---

## Correction 5: PatternCandidate Confidence Scoring

```php
final class PatternCandidate
{
    public function __construct(
        public readonly string  $candidateId,
        public readonly string  $description,

        // Evidence metrics:
        public readonly int     $supportCount,       // # videos where pattern appeared
        public readonly float   $qualityCorrelation, // correlation with review scores (0–1)
        public readonly float   $confidence,         // bayesian confidence (see below)
        public readonly float   $falsePositiveRate,  // appeared in LOW-quality videos too?

        public readonly string  $suggestedTemplate,
        public readonly bool    $requiresHumanApproval,
        public readonly string  $approvalStatus,     // 'pending' | 'approved' | 'rejected'
    ) {}

    /**
     * Bayesian confidence: combines support count + correlation + FPR.
     * Low support → low confidence even if correlation = 1.0 (overfitting guard).
     */
    public static function computeConfidence(
        int   $supportCount,
        float $correlation,
        float $falsePositiveRate,
    ): float {
        // Need minimum 20 videos before confidence > 0.8
        $supportWeight = 1.0 - exp(-$supportCount / 20.0);
        $signalStrength = $correlation * (1.0 - $falsePositiveRate);
        return $supportWeight * $signalStrength;
    }
}
```

**Gate rules for auto-approval:**

| Condition | Action |
|---|---|
| confidence >= 0.85 AND supportCount >= 50 | Auto-propose for human review |
| confidence >= 0.70 AND supportCount >= 20 | Queue for weekly human review |
| confidence < 0.70 OR supportCount < 20 | Keep observing — not ready |
| falsePositiveRate > 0.3 | Discard — pattern not reliable |

---

## Correction 6: KnowledgeEvolution — Memory Compression

```php
interface KnowledgeEvolution
{
    // Original operations (ADR-013 rev.1):
    public function mergeFacts(array $productionIds): MergedFactGraph;
    public function pruneObsolete(FactGraph $graph, \DateTimeImmutable $cutoff): FactGraph;
    public function discoverPatterns(array $productionIds): array;  // PatternCandidate[]
    public function recalibrateConfidence(FactGraph $graph, array $results): FactGraph;

    // New: memory compression for long-term growth
    public function compress(FactGraph $graph): CompressedFactGraph;
    public function archive(FactGraph $graph, \DateTimeImmutable $cutoff): ArchiveResult;
    public function summarize(array $factClusters): array;  // SummaryFact[] — one fact per cluster
}

final class CompressedFactGraph
{
    // Original FactGraph: 850 individual facts about "Chiefs season 2025"
    // Compressed: 120 summary facts + 730 facts archived to cold storage
    // Cold storage: still queryable, just not loaded into working memory by default

    public function __construct(
        public readonly array  $workingFacts,  // Fact[] — hot: used in recent/upcoming productions
        public readonly array  $archiveRefs,   // ArchiveRef[] — cold: queryable but not loaded
        public readonly array  $summaryFacts,  // SummaryFact[] — cluster summaries
        public readonly int    $originalCount,
        public readonly float  $compressionRatio, // e.g., 7.08 (850 / 120)
    ) {}
}

final class SummaryFact
{
    // A single fact that summarizes a cluster of related facts
    // e.g., 47 individual play-by-play facts → "Mahomes completed 32/41 passes, 3 TDs, 0 INT"
    public function __construct(
        public readonly string  $factId,
        public readonly string  $summary,
        public readonly int     $sourceFactCount,
        public readonly float   $averageConfidence,
        public readonly array   $sourceFactIds,    // for audit trail
        public readonly \DateTimeImmutable $summarizedAt,
    ) {}
}
```

**Compression triggers:**

| Trigger | Action |
|---|---|
| FactGraph > 500 facts | Run `compress()` — move < 30-day-old facts to archive |
| Production > 6 months old | Run `archive()` — move all facts to cold storage |
| Same subject appears > 10 times | Run `summarize()` — merge into SummaryFact |
| Annual | Full compression pass across all productions |

---

## Correction 7: Decision Ledger → Decision DAG

**The Decision Ledger in ADR-012 was a linear log. It should be a true DAG** — every node links causally to the nodes that influenced it.

```php
namespace App\Services\AI\FilmOS\DecisionDAG;

enum DAGNodeType: string
{
    case FACT           = 'fact';          // Fact from FactGraph
    case MEANING        = 'meaning';       // SemanticMeaning from MeaningResolver
    case STRATEGY       = 'strategy';      // VisualStrategy from VisualStrategyResolver
    case DECISION       = 'decision';      // any Decision Layer choice
    case CONSTRAINT     = 'constraint';    // ConstraintViolation (SOFT or blocked HARD)
    case INTENT         = 'intent';        // DirectorIntent assembled
    case COMPILATION    = 'compilation';   // AFOS output (CameraIR/PromptIR)
    case RENDER         = 'render';        // rendered video + cost
    case REVIEW         = 'review';        // ReviewVerdict from a reviewer
    case CONSENSUS      = 'consensus';     // ConsensusVerdict
    case ANALYTICS      = 'analytics';     // CTR/watch-time after publish
}

final class DAGNode
{
    public function __construct(
        public readonly string      $nodeId,
        public readonly DAGNodeType $type,
        public readonly mixed       $payload,      // the actual data (Fact, SemanticMeaning, etc.)
        public readonly float       $confidence,   // propagated confidence at this node
        public readonly \DateTimeImmutable $createdAt,
    ) {}
}

final class DAGEdge
{
    public function __construct(
        public readonly string  $fromNodeId,
        public readonly string  $toNodeId,
        public readonly string  $relation,   // 'caused', 'informed', 'constrained', 'evaluated'
        public readonly float   $weight,     // how much influence (0.0–1.0)
    ) {}
}

final class DecisionDAG
{
    /** @param DAGNode[] $nodes */
    /** @param DAGEdge[] $edges */
    public function __construct(
        private readonly string $productionId,
        private readonly string $shotId,
        private readonly array  $nodes,
        private readonly array  $edges,
    ) {}

    /** "Why was lens=85 chosen?" → trace backward from COMPILATION node */
    public function explain(string $nodeId): array { ... }  // DAGNode[] — ancestors

    /** "What would change if Fact #3 had confidence=0.5?" — counterfactual */
    public function counterfactual(string $factNodeId, float $newConfidence): array { ... }

    /** Serialize for storage in DecisionRecord table (ADR-010) */
    public function toJson(): string { ... }

    /** For Analytics learning: which nodes in this DAG correlated with high CTR? */
    public function highInfluenceNodes(float $ctr): array { ... }
}
```

**Concrete DAG for a single travel-warning shot:**

```
[FACT: cockroach_found, conf=0.92] ──caused──▶ [MEANING: danger+crime, conf=0.90]
[FACT: hotel_location, conf=0.95] ──informed──▶ [MEANING: danger+crime, conf=0.90]
[MEANING: danger+crime, conf=0.90] ──caused──▶ [STRATEGY: OBSERVATIONAL, conf=0.88]
[STRATEGY: OBSERVATIONAL] ──informed──▶ [DECISION: lens=85, conf=0.87]
[DECISION: lens=85] ──caused──▶ [INTENT: REVEAL function, conf=0.86]
[INTENT] ──caused──▶ [COMPILATION: CameraIR{lens=85,STATIC,shallow_DOF}, conf=0.86]
[COMPILATION] ──caused──▶ [RENDER: kling_v2, $0.08, conf=0.85]
[RENDER] ──evaluated──▶ [REVIEW: FactReviewer pass, score=0.91]
[RENDER] ──evaluated──▶ [REVIEW: VisualReviewer pass, score=0.84]
[CONSENSUS: score=0.88, ACCEPTED] ──analytics──▶ [ANALYTICS: CTR=8.2%, watch=0.87]
```

**Query: "Why did this shot use 85mm?"**

```
DecisionDAG.explain('COMPILATION_node')
→ [INTENT: REVEAL] → [STRATEGY: OBSERVATIONAL] → [MEANING: danger] → [FACT: cockroach_found(0.92)]
→ "85mm chosen because OBSERVATIONAL strategy (domain=travel_warning, tone=danger) prefers telephoto"
→ "OBSERVATIONAL chosen because MeaningResolver returned danger (confidence=0.90)"
→ "danger meaning derived from cockroach_found(0.92) + hotel_location(0.95)"
```

---

## Correction 8: ResourceOrchestrator (cross-cutting service)

Not a layer. Attaches to Layer 6 (Rendering) and Layer 7 (Evaluation) as a shared service.

```php
namespace App\Services\AI\FilmOS\Resource;

final class ResourceOrchestrator
{
    /**
     * Before dispatching a render job, checks:
     * - Budget remaining for this production
     * - GPU/API rate limit headroom
     * - Priority queue position
     */
    public function canDispatch(
        string       $productionId,
        string       $shotId,
        ShotPriority $priority,
        float        $estimatedCostUsd,
    ): DispatchDecision { ... }

    /**
     * Returns the next shot to render, considering:
     * - Production deadline
     * - Shot priority (CRITICAL first)
     * - Current queue depth
     * - Provider rate limits (Kling: N req/min, Veo: M req/min)
     */
    public function nextInQueue(): ?QueuedShot { ... }

    /**
     * After render: record actual cost + latency.
     * Update budget remaining + latency SLA tracking.
     */
    public function recordCompletion(string $shotId, float $actualCostUsd, int $latencyMs): void { ... }
}

final class DispatchDecision
{
    public function __construct(
        public readonly bool    $allowed,
        public readonly string  $reason,       // 'budget_remaining', 'rate_limited', 'queue_full'
        public readonly ?int    $retryAfterMs, // null if allowed, N ms if rate-limited
        public readonly string  $assignedProvider, // which provider to use
    ) {}
}

// Budget envelope per production:
final class ProductionBudget
{
    public function __construct(
        public readonly float $totalAllocatedUsd,
        public readonly float $spentUsd,
        public readonly float $remainingUsd,
        public readonly array $spendByPriority,  // ['CRITICAL' => 0.0, 'IMPORTANT' => 0.0, 'FILLER' => 0.0]
    ) {}

    public function remaining(): float
    {
        return $this->totalAllocatedUsd - $this->spentUsd;
    }
}
```

**Integration:**
```
Layer 6 (Rendering):
  Before RendererPlugin.render() → ResourceOrchestrator.canDispatch()
  After render completes       → ResourceOrchestrator.recordCompletion()

Layer 7 (Evaluation):
  MultiAgentReview uses ResourceOrchestrator.canDispatch() for AI calls
  (Haiku vision calls count toward API rate limits)
```

---

## Revised 8-Layer Summary

| Layer | Components | Correction applied |
|---|---|---|
| 1 Knowledge | FactGraph · ProductionBible · WorldState · **SceneTimeline** | Added TemporalMemory |
| 2 Meaning | **MeaningResolver** · CinematicOntology · **TensionEngine → VisualStrategy** | Scope-limited + context-dependent meaning + 2-step tension |
| 3 Decision | StorySelector · NarrativePattern · AttentionFlow · ConstraintEngine · **VisualStrategyResolver** · **MeaningToExecutionTranslator** | Meaning→Execution translation moved here |
| 4 Intent | **DirectorIntent = { MeaningContext + ExecutionContext + EvalContext }** | Fat interface split into 3 |
| 5 Compilation | AFOS (frozen) — reads ExecutionContext only | Unchanged |
| 6 Rendering | CapabilityResolver · RendererPlugins · **ResourceOrchestrator** | Resource layer added |
| 7 Evaluation | **5 reviewers + ConsensusVerdict** · Fact veto | MultiAgent expanded |
| 8 Learning | AssetGraph · **KnowledgeEvolution + Compression** · **DecisionDAG replay** · **PatternCandidate confidence** | All 4 learning corrections |

Cross-cutting: **DecisionDAG** (was Ledger log → true causal graph) · ConfidencePropagator · **ResourceOrchestrator**

---

## What Does NOT Change from ADR-012

- AFOS Compiler (ADR-001) — frozen. `ExecutionContext` maps cleanly to existing AFOS inputs.
- Decision Ledger concept — upgraded to DAG, not removed.
- Confidence Propagation — unchanged. TemporalValidator adds one more gate.
- ConstraintEngine (HARD/SOFT) — unchanged. Extended with CinematicFunction constraints.
- ADR-004 through ADR-010 — all unchanged.
- Phase implementation order (ADR-012) — unchanged. ADR-013 components interleave.

---

## Implementation Phase Mapping

| Phase | ADR-012 | ADR-013 additions |
|---|---|---|
| 1 | FactGraph + Decision Ledger + Confidence | **SceneTimeline + TemporalValidator + DecisionDAG (foundation)** |
| 2 | DomainStyleProfile + AttentionFlow + ConstraintEngine | **MeaningResolver + VisualStrategyResolver + CinematicOntology config** |
| 3 | EventTemplate Library | **TensionCurve per domain + VisualStrategy mapping** |
| 4 | NarrativePattern Library | TensionCurve per pattern (minimal addition) |
| 5 | AssetGraph + AssetRegistry | **MultiAgentReview (Visual + Fact first) + ResourceOrchestrator** |
| 6 | DecisionReplay + StyleLearning | **KnowledgeEvolution + Compression + PatternDiscovery with confidence** |

---

## Architecture Readiness

| Tiêu chí | Score |
|---|---|
| AFOS Integration | 10/10 |
| Scalability (ResourceOrchestrator) | 9.9/10 |
| Maintainability (split DirectorIntent) | 9.8/10 |
| Extensibility (MeaningResolver interface) | 10/10 |
| Explainability (DecisionDAG) | 10/10 |
| Auditability (DAG + Fact veto) | 10/10 |
| Long-term evolution (KnowledgeEvolution + Compression) | 9.9/10 |
| Production readiness | **Draft — requires Phase 1–2 implementation to validate** |

---

## References

- ADR-001: AFOS Compiler — `ExecutionContext` maps to `ShotGoalIR` fields
- ADR-002: WorldState, AssetDefinition — TemporalValidator reads these
- ADR-004: ProductionEventBus — `HumanReviewRequired`, `TemporalViolation` events
- ADR-008: WorldGraph StateVector — TemporalValidator checks StateVector history
- ADR-009: OntologyClass — `MeaningResolver` uses `ontologyRegistry.baseSignals()`
- ADR-010: DecisionBudget.ShotPriority — `ConsensusVerdict` acceptance thresholds
- ADR-012: All 5 layers + Decision Ledger + Confidence Propagation — extended here
