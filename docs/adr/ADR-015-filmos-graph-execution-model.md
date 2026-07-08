# ADR-015: FilmOS Graph Execution Model

**Status:** Draft  
**Date:** 2026-07-08  
**Revision:** 1  
**Deciders:** Chief Architect + Project Lead  
**Amends:** ADR-014 (FilmOS Architecture v3.0 — Graph-First Architecture)  
**Scope:** 5 foundational clarifications before Phase 1 — these define how FilmOS thinks and executes, not just how it is organized

---

## Context

ADR-014 established Graph-First as Principle #1, introduced FilmPlanner, made FilmKernel task-agnostic, and connected the closed loop. What it did not yet specify is **how the graph executes** — the difference between the logical layer model and the runtime execution model.

Five gaps remain:

1. Layers are described as ordered (1→8), but the execution graph is not a sequence
2. `NarrativeGoal` is still an object — goals should form a graph so the planner can reason about dependencies between goals, not just between shots
3. `MeaningGraph` has string relation types — making them typed causal relations unlocks explanation power ("cockroach CAUSES health risk" vs. "cockroach related to danger")
4. Planning optimizes only narrative — no cost, latency, or predicted review score
5. Learning is reactive (analytics → hypothesis → A/B test) — Planner cannot query what Learning knows before committing to a render

This ADR adds **Principle 4**, introduces **GoalGraph** and **CausalMeaningGraph**, and adds **Multi-objective Planning** and **PredictiveLearning** as first-class concepts.

---

## Principle 4 — Layer order and execution order are different things

The 8 layers are **logical groupings** — a way to organize responsibilities, ownership, and reasoning. They are not a runtime execution sequence.

```
Logical layer order (how we organize code):
  Knowledge → Meaning → Planning → Intent → Compilation → Rendering → Evaluation → Learning

Execution order (how DAGRuntime actually runs tasks):
  Determined entirely by task dependencies, not layer numbers.
```

**Concrete examples of execution deviating from layer order:**

| Situation | Layer order says | Execution actually does |
|---|---|---|
| PredictiveLearning | Learning (8) runs after Rendering (6) | Learning (8) answers Planning (3) before any render |
| Continuous knowledge update | Learning (8) runs once at the end | Learning (8) updates Knowledge (1) after every accepted review — concurrent with new planning |
| Speculative evaluation | Evaluation (7) runs after Rendering (6) | EvaluationPlugin estimates shot quality before render to inform planning cost score |
| Fact verification | Knowledge (1) is a source | Fact veto reviewer (7) queries Knowledge (1) during evaluation — reverse direction |

**Consequence for implementation:** When wiring `DAGRuntime.execute()` calls, the parent dependencies determine order. Layer membership determines code organization and ownership. These are separate decisions.

**Corollary:** The closed loop is NOT a cycle in the DAGRuntime (a DAG cannot have cycles). It is a loop at the production level: Learning updates the shared Knowledge graph; the NEXT production's DAGRuntime starts from a richer Knowledge state. Within one production, the graph is acyclic. Across productions, the system evolves.

---

## Change 1: GoalGraph replaces flat NarrativeGoal

### Why

ADR-014 `NarrativeGoal` was a flat object:

```php
final class NarrativeGoal {
    public readonly string  $intent;     // "Reveal corruption"
    public readonly array   $requirements;
    public readonly float   $priority;
    public readonly ?int    $maxShots;
}
```

Goals have dependencies on other goals. "Show consequence" requires "Show rescue" which requires "Show damage" which requires "Show location". That is a graph — and the planner needs to traverse it to determine execution order, identify parallelism, and respect priorities.

A flat object forces `GoalDecomposer` to hardcode these dependencies. A graph makes them explicit, queryable, and extensible.

### Change: GoalGraph

```php
namespace App\Services\AI\FilmOS\Planning;

enum GoalNodeType: string
{
    case ROOT         = 'root';         // top-level goal — entry point
    case INTERMEDIATE = 'intermediate'; // requires further decomposition
    case LEAF         = 'leaf';         // directly maps to one shot
}

enum GoalRelation: string
{
    case REQUIRES  = 'requires';   // B cannot run until A completes
    case SUPPORTS  = 'supports';   // A increases B's quality but is not mandatory
    case CONFLICTS = 'conflicts';  // if A is prioritized, B's weight decreases
    case ENABLES   = 'enables';    // A must exist for B to be possible (weaker than REQUIRES)
}

final class GoalNode
{
    public function __construct(
        public readonly string       $nodeId,
        public readonly string       $intent,      // "Show location of accident", "Capture witness emotion"
        public readonly GoalNodeType $type,
        public readonly float        $priority,    // 0.0–1.0 — relative to sibling goals
        public readonly float        $weight,      // narrative contribution to parent goal
        public readonly ?int         $maxShots,    // optional shot budget for this node
    ) {}
}

final class GoalEdge
{
    public function __construct(
        public readonly string       $fromNodeId,  // prerequisite
        public readonly string       $toNodeId,    // dependent
        public readonly GoalRelation $relation,
        public readonly float        $strength,    // 0.0–1.0
    ) {}
}

final class GoalGraph
{
    public function __construct(
        public readonly array  $nodes,       // GoalNode[]
        public readonly array  $edges,       // GoalEdge[]
        public readonly string $rootNodeId,
    ) {}

    public function root(): GoalNode
    {
        return $this->nodeById($this->rootNodeId);
    }

    /** Terminal nodes — each maps to exactly one shot */
    public function leaves(): array  // GoalNode[]
    {
        $hasOutgoing = array_unique(array_map(fn($e) => $e->fromNodeId, $this->edges));
        return array_values(array_filter($this->nodes, fn($n) => !in_array($n->nodeId, $hasOutgoing)));
    }

    /** Goals that must complete before this one can start */
    public function prerequisites(string $nodeId): array  // GoalNode[]
    {
        return array_values(array_filter(
            array_map(fn($e) => $this->nodeById($e->fromNodeId),
                array_filter($this->edges, fn($e) => $e->toNodeId === $nodeId && $e->relation === GoalRelation::REQUIRES)
            )
        ));
    }

    /** Topological sort respecting REQUIRES edges — execution order for leaf goals */
    public function topoSort(): array  // GoalNode[]
    {
        // Kahn's algorithm on REQUIRES edges only
        // SUPPORTS and ENABLES edges inform weight, not order
    }

    /** Total shot budget (sum of leaf node maxShots, or estimated 1 shot per leaf) */
    public function totalShots(): int
    {
        return array_sum(array_map(fn($n) => $n->maxShots ?? 1, $this->leaves()));
    }

    private function nodeById(string $id): GoalNode
    {
        return array_values(array_filter($this->nodes, fn($n) => $n->nodeId === $id))[0];
    }
}
```

**Example GoalGraph for "Explain accident" (5 goals, 4 dependency edges):**

```
[ROOT: "Explain accident"]
    REQUIRES → [INTERMEDIATE: "Show cause"]
                   REQUIRES → [LEAF: "Show location of impact", maxShots=1]
                   REQUIRES → [LEAF: "Show point of failure",  maxShots=1]
    REQUIRES → [INTERMEDIATE: "Show consequence"]
                   REQUIRES → [LEAF: "Show damage extent",     maxShots=2]
                   ENABLES  → [LEAF: "Show rescue operation",  maxShots=1]
                   SUPPORTS → [LEAF: "Show human reaction",    maxShots=1]
```

`topoSort()` output: location → failure → damage → rescue → human reaction  
`leaves()` output: 5 goals → 5 shots (one per leaf)  

**Updated FilmPlanner interface:**

```php
interface FilmPlanner
{
    public function plan(
        GoalGraph        $goals,        // was: NarrativeGoal
        MeaningGraph     $meaning,
        WorldState       $world,
        PlanObjectives   $objectives,   // new: multi-objective (Change 3)
        ConstraintEngine $constraints,
    ): ShotSequencePlan;
}
```

**Updated GoalDecomposer:**

```php
final class GoalDecomposer
{
    /**
     * Given a high-level intent string, build the GoalGraph by:
     * 1. Matching intent to a template in ProductionBible (Layer 1)
     * 2. Instantiating GoalNodes and GoalEdges from the template
     * 3. Modulating priorities based on MeaningGraph root confidence
     */
    public function decompose(
        string       $intent,
        MeaningGraph $meaning,
    ): GoalGraph
    {
        // Template lookup from ProductionBible — no hardcoding here
        // Templates are the learned patterns (accepted HypothesisVerdicts)
    }
}
```

---

## Change 2: CausalMeaningGraph — typed causal relations

### Why

ADR-014 `MeaningEdge.relation` was a plain string: `'supports' | 'implies' | 'contradicts' | 'modulates'`. These are semantic labels — they describe association, not causation.

The user's example makes the gap clear:

```
cockroach → danger             (semantic: both related to "bad")
cockroach → unsanitary → health risk → travel warning   (causal: A CAUSES B)
```

The causal chain is the reasoning chain. When a journalist asks "why does this shot have a travel warning overlay?", the system should trace: `cockroach CAUSES unsanitary ESCALATES health_risk ESCALATES travel_warning`. That is not possible with flat semantic labels.

Typed causal relations also enable conflict detection: if two facts produce MeaningNodes with `CONTRADICTS` edges, the planner knows the scene has ambiguity and may need an extra clarification shot.

### Change: CausalRelation enum replaces string

```php
namespace App\Services\AI\FilmOS\Meaning;

enum CausalRelation: string
{
    case CAUSES      = 'causes';      // A directly produces B: cockroach CAUSES unsanitary
    case ESCALATES   = 'escalates';   // A intensifies into B: health_risk ESCALATES travel_warning
    case INDICATES   = 'indicates';   // A is evidence of B: broken_lock INDICATES security_breach
    case CONTRADICTS = 'contradicts'; // A weakens or negates B: "clean lobby" CONTRADICTS "dirty_room"
    case ENABLES     = 'enables';     // A makes B possible: location_context ENABLES damage_comprehension
    case MODULATES   = 'modulates';   // A adjusts the weight of B without causing it
}
```

**Updated MeaningEdge:**

```php
final class MeaningEdge
{
    public function __construct(
        public readonly string        $fromNodeId,
        public readonly string        $toNodeId,
        public readonly CausalRelation $relation,  // was: string
        public readonly float         $strength,
    ) {}
}
```

**Updated MeaningGraph — adds causal traversal methods:**

```php
final class MeaningGraph
{
    // ... existing constructor (nodes, edges, rootNodeId, confidence, resolvedBy) unchanged ...

    public function root(): MeaningNode { ... }
    public function causalChain(): array { ... }   // unchanged
    public function above(float $threshold): array { ... }  // unchanged

    /** All nodes reachable from $nodeId via CAUSES or ESCALATES edges */
    public function causalDescendants(string $nodeId): array  // MeaningNode[]
    {
        // BFS following only CAUSES and ESCALATES edges
    }

    /** All CONTRADICTS pairs — signals ambiguity requiring extra clarification shot */
    public function contradictions(): array  // [MeaningNode, MeaningNode][]
    {
        return array_map(
            fn($e) => [$this->nodeById($e->fromNodeId), $this->nodeById($e->toNodeId)],
            array_filter($this->edges, fn($e) => $e->relation === CausalRelation::CONTRADICTS)
        );
    }

    /** Whether this meaning graph has unresolved contradictions */
    public function hasAmbiguity(): bool
    {
        return !empty($this->contradictions());
    }

    private function nodeById(string $id): MeaningNode { ... }
}
```

**Example — typed causal reasoning for "hotel hygiene violation":**

```
[cockroach, w=0.95]
    CAUSES:0.92      → [unsanitary_condition, w=0.91]
    ESCALATES:0.88   → [health_risk, w=0.87]
    ESCALATES:0.85   → [travel_warning, w=0.84]   ← root
    CAUSES:0.83      → [avoid_destination, w=0.82]

[clean_lobby_photo, w=0.60]
    CONTRADICTS:0.55 → [dirty_room, w=0.88]       ← contradiction detected

→ MeaningGraph.hasAmbiguity() = true
→ Planner adds a "clarification shot" sub-goal to GoalGraph
→ Shot: wide shot showing both lobby and room hallway, camera slow pan from clean to dirty
```

**Explanation query chain:**
```
"Why is there a travel warning on this shot?"
→ travel_warning ← ESCALATES ← health_risk ← ESCALATES ← unsanitary ← CAUSES ← cockroach
→ "Travel warning shown because cockroach finding caused an unsanitary condition
   which escalated to health risk which escalated to travel warning advisory."
```

---

## Change 3: Multi-objective Planning

### Why

ADR-014 `SequenceOptimizer` optimized only narrative flow. In production, a plan that scores 0.95 on narrative but costs $4.00 and takes 90 seconds may be worse than a plan scoring 0.89 on narrative but costing $0.80 and taking 20 seconds, especially for breaking news.

The planner must reason about all four objectives simultaneously:
- **Narrative quality**: how well the shot sequence achieves the GoalGraph
- **Render cost**: estimated USD to produce all shots in the plan
- **Latency**: estimated time to complete all renders (wall clock)
- **Expected review score**: what the MultiAgentReview will likely return (from PredictiveLearning)

### Change: PlanObjectives + PlanScore + MultiObjectiveOptimizer

```php
namespace App\Services\AI\FilmOS\Planning;

final class PlanObjectives
{
    public function __construct(
        // Weights: must sum to 1.0. Define importance of each objective.
        public readonly float $narrativeWeight,      // importance of narrative quality
        public readonly float $costWeight,           // importance of cost efficiency
        public readonly float $latencyWeight,        // importance of speed
        public readonly float $reviewScoreWeight,    // importance of predicted review pass rate

        // Hard caps: plans violating these are discarded before scoring
        public readonly float $maxCostUsd,           // absolute budget ceiling
        public readonly int   $maxLatencyMs,         // absolute deadline
        public readonly float $minReviewScore,       // minimum acceptable predicted review score
    ) {
        // weights must sum to 1.0 — caller's responsibility; no normalization here
    }
}

final class PlanScore
{
    public function __construct(
        public readonly float  $narrativeScore,        // 0.0–1.0 — how well plan achieves GoalGraph
        public readonly float  $estimatedCostUsd,      // sum of per-shot render cost estimates
        public readonly int    $estimatedLatencyMs,    // critical path through dependency graph
        public readonly float  $expectedReviewScore,   // from PredictiveLearning
        public readonly float  $composite,             // weighted sum per PlanObjectives
    ) {}

    public function meetsHardCaps(PlanObjectives $objectives): bool
    {
        return $this->estimatedCostUsd  <= $objectives->maxCostUsd
            && $this->estimatedLatencyMs <= $objectives->maxLatencyMs
            && $this->expectedReviewScore >= $objectives->minReviewScore;
    }
}

final class MultiObjectiveOptimizer
{
    public function __construct(
        private readonly PredictiveLearning $predictor,
        private readonly CostEstimator      $costEstimator,  // estimates render cost per shot
        private readonly LatencyEstimator   $latencyEstimator,
    ) {}

    /**
     * Score all candidate plans, eliminate hard-cap violations,
     * then select from the Pareto front using objective weights.
     *
     * @param  ShotSequencePlan[] $candidates
     */
    public function optimize(
        array          $candidates,
        PlanObjectives $objectives,
        array          $contextFeatures,
    ): ShotSequencePlan {
        // Step 1: Score each candidate
        $scored = array_map(fn($plan) => [
            'plan'  => $plan,
            'score' => $this->score($plan, $objectives, $contextFeatures),
        ], $candidates);

        // Step 2: Eliminate hard-cap violations
        $valid = array_filter($scored, fn($s) => $s['score']->meetsHardCaps($objectives));

        if (empty($valid)) {
            // All plans exceed hard caps — return cheapest plan with a warning
            usort($scored, fn($a, $b) => $a['score']->estimatedCostUsd <=> $b['score']->estimatedCostUsd);
            return $scored[0]['plan']->withWarning('hard_cap_violated');
        }

        // Step 3: Compute Pareto front (no plan is dominated on all objectives)
        $pareto = $this->paretoFront(array_values($valid));

        // Step 4: Pick highest composite score from Pareto front
        usort($pareto, fn($a, $b) => $b['score']->composite <=> $a['score']->composite);
        return $pareto[0]['plan']->withScore($pareto[0]['score']);
    }

    private function score(
        ShotSequencePlan $plan,
        PlanObjectives   $objectives,
        array            $contextFeatures,
    ): PlanScore {
        $prediction = $this->predictor->predict($plan, $contextFeatures);
        $cost       = $this->costEstimator->estimate($plan);
        $latency    = $this->latencyEstimator->estimate($plan);

        // Normalize: higher is always better before weighting
        $normalizedCost    = max(0.0, 1.0 - ($cost / ($objectives->maxCostUsd ?: 1.0)));
        $normalizedLatency = max(0.0, 1.0 - ($latency / ($objectives->maxLatencyMs ?: 1)));

        $composite =
            $plan->goalConfidence          * $objectives->narrativeWeight
            + $normalizedCost              * $objectives->costWeight
            + $normalizedLatency           * $objectives->latencyWeight
            + $prediction->expectedReviewScore * $objectives->reviewScoreWeight;

        return new PlanScore(
            narrativeScore:      $plan->goalConfidence,
            estimatedCostUsd:    $cost,
            estimatedLatencyMs:  $latency,
            expectedReviewScore: $prediction->expectedReviewScore,
            composite:           $composite,
        );
    }

    /**
     * Pareto front: plan A dominates plan B if A is at least as good on all objectives
     * and strictly better on at least one. Only non-dominated plans are returned.
     */
    private function paretoFront(array $scored): array
    {
        $front = [];
        foreach ($scored as $candidate) {
            $dominated = false;
            foreach ($scored as $other) {
                if ($this->dominates($other['score'], $candidate['score'])) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $front[] = $candidate;
            }
        }
        return $front;
    }

    private function dominates(PlanScore $a, PlanScore $b): bool
    {
        // A dominates B: A is >= B on all objectives and > B on at least one
        $aScores = [$a->narrativeScore, 1-$a->estimatedCostUsd, 1-$a->estimatedLatencyMs, $a->expectedReviewScore];
        $bScores = [$b->narrativeScore, 1-$b->estimatedCostUsd, 1-$b->estimatedLatencyMs, $b->expectedReviewScore];
        $atLeastAsGood = !in_array(false, array_map(fn($as, $bs) => $as >= $bs, $aScores, $bScores));
        $strictlyBetter = in_array(true, array_map(fn($as, $bs) => $as > $bs, $aScores, $bScores));
        return $atLeastAsGood && $strictlyBetter;
    }
}
```

**Updated ShotSequencePlan:**

```php
final class ShotSequencePlan
{
    public function __construct(
        public readonly GoalGraph  $goals,             // was: NarrativeGoal
        public readonly array      $shots,             // PlannedShot[]
        public readonly float      $goalConfidence,
        public readonly array      $subGoalsCovered,
        public readonly ?PlanScore $score,             // null until MultiObjectiveOptimizer runs
        public readonly array      $alternatives,      // ShotSequencePlan[] — runner-up plans
        public readonly string     $planId,
        public readonly ?string    $warning,           // non-null if hard caps were violated
    ) {}

    public function withScore(PlanScore $score): self { ... }
    public function withWarning(string $warning): self { ... }
}
```

**Preset PlanObjectives for common scenarios:**

```php
// Breaking news: speed above all else
PlanObjectives::breakingNews(maxCostUsd: 1.00, maxLatencyMs: 15_000);
// weights: narrative=0.30, cost=0.25, latency=0.35, review=0.10

// Feature documentary: quality above all else
PlanObjectives::documentary(maxCostUsd: 5.00, maxLatencyMs: 120_000);
// weights: narrative=0.50, cost=0.15, latency=0.05, review=0.30

// Budget-constrained: cost above all else
PlanObjectives::budgetConstrained(maxCostUsd: 0.50);
// weights: narrative=0.25, cost=0.45, latency=0.20, review=0.10
```

---

## Change 4: PredictiveLearning — Learning answers Planning before render

### Why

ADR-014 Learning was reactive:

```
render → analytics → hypothesis → A/B test → accept/reject → update knowledge
```

This means every production must render before Learning can inform the next decision. For a news organization producing dozens of videos per day, "render first, learn later" wastes budget on plans that Learning could already predict will underperform.

**The change:** Planner can query Learning's current knowledge state before committing to a render. Learning answers: "Based on past similar productions, this plan will likely get CTR=8.2%, review score=0.87, confidence=0.81."

This changes Learning from a post-production step into a real-time advisor.

```php
namespace App\Services\AI\FilmOS\Learning;

final class PredictionResult
{
    public function __construct(
        public readonly float  $expectedCtr,            // e.g., 0.082 (8.2%)
        public readonly float  $expectedWatchTime,      // 0.0–1.0 (fraction watched)
        public readonly float  $expectedReviewScore,    // predicted MultiAgentReview score
        public readonly float  $confidence,             // how reliable this prediction is
        public readonly int    $comparableProductions,  // sample size for this prediction
        public readonly string $basedOn,                // "47 crime_documentary + OBSERVATIONAL + 135mm productions"
    ) {}

    /** Prediction is reliable enough to act on */
    public function isReliable(float $minConfidence = 0.70, int $minSamples = 20): bool
    {
        return $this->confidence >= $minConfidence
            && $this->comparableProductions >= $minSamples;
    }
}

interface PredictiveLearning
{
    /**
     * Before rendering: estimate expected outcomes for a candidate plan.
     * Returns a prediction based on the current LearningGraph state.
     *
     * Called by MultiObjectiveOptimizer during plan scoring.
     * Must be fast (in-memory lookup against KnowledgeGraph, no AI inference).
     */
    public function predict(
        ShotSequencePlan $plan,
        array            $contextFeatures,  // ['domain' => 'crime_documentary', 'audience' => 'news', ...]
    ): PredictionResult;

    /**
     * After evaluation: update the internal model with real outcomes.
     * Called by KnowledgeEvolution after each accepted production.
     */
    public function calibrate(
        ShotSequencePlan $plan,
        array            $actualOutcomes,   // ['ctr' => 0.088, 'watch_time' => 0.82, 'review_score' => 0.91]
    ): void;
}
```

**Integration with MultiObjectiveOptimizer (from Change 3):**

```php
// Inside MultiObjectiveOptimizer::score():
$prediction = $this->predictor->predict($plan, $contextFeatures);

if (!$prediction->isReliable()) {
    // Fall back to narrative score only when prediction is unreliable
    // Don't penalize unknown domains
    $expectedReviewScore = 0.70;  // neutral prior
} else {
    $expectedReviewScore = $prediction->expectedReviewScore;
}
```

**Integration with KnowledgeEvolution:**

```php
// After production completes and analytics are collected:
$knowledgeEvolution->recalibrateConfidence($graph, $results);
$predictiveLearning->calibrate($plan, $actualOutcomes);
// PredictiveLearning is now updated — next Planner query will reflect new data
```

**When PredictiveLearning has no data (new domain):**

```php
final class PredictionResult
{
    public static function noPrior(string $reason): self
    {
        return new self(
            expectedCtr:            0.05,  // industry baseline
            expectedWatchTime:      0.60,
            expectedReviewScore:    0.70,
            confidence:             0.20,  // low confidence — unreliable
            comparableProductions:  0,
            basedOn:                $reason,
        );
    }
}
```

---

## Graph Execution Model — how it all fits

With ADR-015, every layer is now explicitly a graph and the execution model is the DAGRuntime:

```
FactGraph (Layer 1)
    │
    ▼ [MeaningResolver.resolve() → DAGRuntime.execute(MEANING, parents=[FACT])]
CausalMeaningGraph (Layer 2)
    │
    ▼ [GoalDecomposer.decompose() → DAGRuntime.execute(STRATEGY, parents=[MEANING])]
GoalGraph (Layer 3 input)
    │
    ▼ [FilmPlanner.plan() + MultiObjectiveOptimizer → DAGRuntime.execute(STRATEGY)]
ShotSequencePlan (Layer 3 output)  ←──── PredictiveLearning (Layer 8, called here)
    │
    ▼ [IntentAssembler: one DAGNode per shot → DAGRuntime.execute(INTENT, parents=[STRATEGY])]
DirectorIntent[] (Layer 4)
    │
    ▼ [AFOS Compiler → DAGRuntime.execute(COMPILATION, parents=[INTENT])]
PromptIR (Layer 5)
    │
    ▼ [FilmKernel.tick() → RenderPlugin.execute() → DAGRuntime.execute(RENDER, parents=[COMPILATION])]
RenderResult (Layer 6)
    │
    ▼ [EvaluationPlugin.execute() → DAGRuntime.execute(REVIEW, parents=[RENDER])]
ConsensusVerdict (Layer 7)
    │
    ▼ [KnowledgeEvolution → updates FactGraph + calibrates PredictiveLearning]
updated Knowledge state (Layer 8 → Layer 1, next production)
```

**Key runtime properties:**

| Property | Value |
|---|---|
| Execution order | Topological sort of DAGRuntime dependency edges |
| PredictiveLearning | Called at Layer 3 (planning), before any render — reverse of layer order |
| Fact veto reviewer | Queries Layer 1 (Knowledge) during Layer 7 (Evaluation) — also reverse |
| MeaningResolver ambiguity | `hasAmbiguity()` triggers additional GoalNode in GoalGraph — feedback within Layer 2→3 |
| Layer 8 → Layer 1 | Not a cycle in the DAG; knowledge update creates richer state for NEXT production's DAG |

---

## Updated 8-Layer Summary

| Layer | ADR-013 | ADR-014 Rev 2 | ADR-015 |
|---|---|---|---|
| 1 Knowledge | FactGraph | Unchanged | Facts queried from any layer (veto reviewer, PredictiveLearning calibration) |
| 2 Meaning | SemanticMeaning | MeaningGraph | **CausalMeaningGraph** — typed `CausalRelation` enum; `contradictions()` method |
| 3 Planning | Decision (linear) | FilmPlanner (goal decomp) | **GoalGraph** as input; **MultiObjectiveOptimizer** selects plan across 4 objectives |
| 4 Intent | DirectorIntent | PlannedShot → DirectorIntent | `DirectorIntent.planId` links back to GoalGraph for DAG tracing |
| 5 Compilation | AFOS (frozen) | Unchanged | Unchanged |
| 6 Rendering | ResourceOrchestrator | FilmKernel + KernelPlugin | Unchanged |
| 7 Evaluation | MultiAgentReview | EvaluationPlugin | Queries Layer 1 (Fact veto) — cross-layer execution confirmed |
| 8 Learning | Passive | HypothesisGenerator + closed loop | **PredictiveLearning** — answers Layer 3 queries before render; calibrates on real outcomes |

Cross-cutting: **DAGRuntime** (execution model) · ConfidencePropagator · **FilmKernel** · **PredictiveLearning**

---

## What Does NOT Change from ADR-014

- All three Principles from ADR-014 — Principle 4 is additive
- `FilmPlanner` interface — signature updated to `GoalGraph` instead of `NarrativeGoal`
- `SubGoalPlanner` cross-voting mechanism — unchanged
- `DecisionCandidate` + `DecisionStrategy` — unchanged
- `DAGRuntime` — unchanged
- `FilmKernel` + plugin model — unchanged
- `HypothesisGenerator` + `HypothesisTester` — unchanged
- `KnowledgeEvolution` base + 4 new methods — unchanged
- AFOS Compiler (ADR-001) — frozen
- All ADR-001 through ADR-013 — unchanged

---

## Implementation Phase Mapping

| Phase | ADR-014 | ADR-015 addition |
|---|---|---|
| 1 | DAGRuntime skeleton. FilmKernel + TaskScheduler. RenderPlugin. | **CausalMeaningGraph** with typed CausalRelation. GoalGraph data structure (no templates yet). |
| 2 | MeaningGraph. FilmPlanner + GoalDecomposer + SubGoalPlanner (2 strategies). | GoalGraph templates in ProductionBible (2 domains). **PlanObjectives** (narrative + cost weights only). |
| 3 | 5 DecisionStrategies. SequenceOptimizer. | **MultiObjectiveOptimizer** (all 4 objectives). CostEstimator + LatencyEstimator stubs. |
| 4 | NarrativeGoal templates in ProductionBible. Scale sequence length. | GoalGraph templates (all 6 domains). GoalRelation traversal tested. |
| 5 | MeaningPlugin + EvaluationPlugin + PlanningPlugin. MemoryManager. | **PredictiveLearning stub** (returns neutral prior until enough data). |
| 6 | HypothesisGenerator + ABTestPlan + HypothesisTester. Closed loop. | **PredictiveLearning full model** — calibrates on real outcomes; Planner queries before render. |

---

## Architecture Readiness

| Tiêu chí | ADR-014 Rev 2 | ADR-015 |
|---|---|---|
| Graph-First (Principle 1) | 10.0 | 10.0 |
| Layer ≠ Execution Order | 7.0 (implied) | **10.0 (explicit, documented)** |
| Causal reasoning | 8.0 (semantic) | **10.0 (typed causal chain)** |
| Goal-level planning | 8.5 (flat object) | **10.0 (GoalGraph with topoSort)** |
| Multi-objective optimization | 0.0 | **10.0 (Pareto front + 4 objectives)** |
| Predictive learning | 0.0 | **10.0 (pre-render prediction)** |
| Closed-loop evolution | 10.0 | 10.0 |
| Production readiness | Draft | **Draft — ready for Phase 1** |

---

## References

- ADR-014 Rev 2 (Amends): Graph-First principles 1–3, FilmKernel plugin model, FilmPlanner — all unchanged
- ADR-013 Rev 3: 8-layer architecture, DirectorIntent split, DAGRuntime — all unchanged
- ADR-001: AFOS Compiler — ExecutionContext from SubGoalPlanner, contract unchanged
- ADR-009: OntologyClass — CausalMeaningGraph nodes reference ontology IDs
- ADR-010: ShotPriority — FilmKernel task scheduler, PlanObjectives hard caps
