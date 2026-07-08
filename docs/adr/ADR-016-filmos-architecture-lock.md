# ADR-016: FilmOS Architecture Lock — Invariants, Golden Scenario & Walking Skeleton

**Status:** Accepted  
**Date:** 2026-07-08  
**Revision:** 1  
**Deciders:** Chief Architect + Project Lead  
**Type:** Architecture Lock (not a foundational ADR — no new concepts)  
**Closes:** ADR-012 through ADR-015. No further foundational ADRs before Phase 6.  
**Scope:** Declare 6 architectural invariants; define the Golden Scenario for architecture validation; specify Phase 1 Walking Skeleton

---

## Context

ADR-012 through ADR-015 have answered the four foundational questions:

| ADR | Question answered |
|---|---|
| ADR-012 | What does the system consist of? (8-layer roadmap) |
| ADR-013 | How does the system understand the world? (Meaning architecture) |
| ADR-014 | How does the system organize knowledge? (Graph-First OS) |
| ADR-015 | How does the system execute? (Graph Execution Model) |

The architecture is now **closed**: adding more foundational ADRs risks overlap rather than clarity. What is needed before Phase 1 is not more architecture — it is **validation that the architecture holds together** when concrete data flows through it.

This ADR does three things:

1. **Locks 6 invariants** — the architectural constitution of FilmOS
2. **Defines the Golden Scenario** — a concrete end-to-end trace that validates all abstractions
3. **Specifies the Phase 1 Walking Skeleton** — the minimal implementation that makes the Golden Scenario run

---

## Part 1: The 6 Architectural Invariants

These invariants are permanent. Every ADR and every PR after this date can be evaluated against them. If a change violates one, the invariant wins — not the convenience.

### Invariant 1 — Everything meaningful is represented as a graph

Facts, meanings, goals, execution traces, evaluations, learned patterns — all are graphs. Services never exchange disconnected scalar objects when the information has relational structure.

**Violation examples:**
- ❌ Returning `['danger', 'crime']` array from MeaningResolver instead of `MeaningGraph`
- ❌ Passing `string $goalIntent` to FilmPlanner instead of `GoalGraph`
- ❌ Storing DecisionDAG as a flat log table with no edge rows

**Detection:** If a layer boundary crosses using a flat array or scalar that could be a graph node, it violates Invariant 1.

---

### Invariant 2 — Layers are logical boundaries, not execution order

The 8-layer labels describe code organization and ownership. They do not determine runtime execution sequence. Execution order is the topological sort of `DAGRuntime` dependency edges.

**Violation examples:**
- ❌ Blocking PredictiveLearning (Layer 8) from answering FilmPlanner (Layer 3) queries before render
- ❌ Preventing Fact veto reviewer (Layer 7) from querying FactGraph (Layer 1) during evaluation
- ❌ Code comment saying "Layer 7 can only receive data from Layer 6"

**Detection:** If any code enforces a sequential layer number check before allowing a function call, it violates Invariant 2.

---

### Invariant 3 — Execution is driven by DAGRuntime

Every operation that produces a meaningful output is wrapped in `DAGRuntime.execute()`. There is no parallel logging, no dual-write. The DAG node IS the operation result.

**Violation examples:**
- ❌ `$meaning = $resolver->resolve(...); $dag->log('meaning', $meaning);`
- ❌ Calling `$resolver->resolve()` without wrapping in `DAGRuntime.execute()`
- ❌ A production that produces video but has no corresponding DAGRuntime node

**Detection:** Any operation that produces a Layer 2–8 output outside `DAGRuntime.execute()` violates Invariant 3.

---

### Invariant 4 — Planning optimizes multiple objectives, not only narrative quality

Every `ShotSequencePlan` is selected by `MultiObjectiveOptimizer` considering narrative quality, render cost, latency, and expected review score. No plan selection based solely on narrative score.

**Violation examples:**
- ❌ Picking the plan with highest `goalConfidence` without computing `PlanScore.composite`
- ❌ Ignoring `PlanObjectives.maxCostUsd` hard cap
- ❌ Not querying `PredictiveLearning` when calibrated data exists with `confidence >= 0.70`

**Detection:** Any planning code path that produces a `ShotSequencePlan` without a `PlanScore` violates Invariant 4.

---

### Invariant 5 — Every output is traceable back to its source facts

Given any rendered shot or prompt, it must be possible to traverse the DAGRuntime graph back to the original `FactNode(s)` that influenced it. No "orphan" nodes.

**The trace-back test** (runs before each phase sign-off):

```
Video / Prompt
  ↑ [RENDER ← COMPILATION edge]
PromptIR
  ↑ [COMPILATION ← INTENT edge]
DirectorIntent
  ↑ [INTENT ← STRATEGY edge]
ShotSequencePlan
  ↑ [STRATEGY ← MEANING edge]
CausalMeaningGraph
  ↑ [MEANING ← FACT edge]
FactNode (source article text)
```

If any edge is missing, the trace is broken and the implementation violates Invariant 5.

**Violation examples:**
- ❌ A prompt built from a static template with no fact reference in the DAG
- ❌ A MeaningNode with no parent FactNode edge
- ❌ A GoalNode with no MEANING parent in the DAG

---

### Invariant 6 — Learning feeds Planning before execution, not only after

`PredictiveLearning.predict()` is called during planning (before any render) when calibrated data exists (`confidence >= 0.70`, `comparableProductions >= 20`). Learning is never only a post-render analytics step.

**Violation examples:**
- ❌ Calling `PredictiveLearning.predict()` only after `KnowledgeEvolution` runs
- ❌ Scoring `ShotSequencePlan` candidates without `expectedReviewScore` from `PredictiveLearning`
- ❌ Implementing `PredictiveLearning` as a background batch job that cannot be queried synchronously

**Detection:** If `MultiObjectiveOptimizer.score()` does not call `PredictiveLearning.predict()`, Invariant 6 is violated.

---

## Part 2: The Golden Scenario

**"Travel Warning — Hotel Hygiene Violation"**  
This scenario traverses all 8 layers with concrete data. If this scenario runs end-to-end, every abstraction in ADR-012 through ADR-015 has been validated.

---

### Input (Layer 0 — raw article)

```
Headline: "Health Inspector Finds Cockroach Infestation at 3-Star Bali Resort"
Body:     "A routine health inspection on 2026-07-08 revealed cockroach infestation
           in multiple guest rooms at the Sunset Palace Resort in Bali. The local
           health department has issued a formal warning. Travelers are advised
           to avoid the property until further notice."
Source:   Travel News Asia
```

---

### Layer 1: FactGraph output

```
Facts (ArticleVideoFact[]):
  F1: "Cockroach infestation found in multiple guest rooms"
      category=EVIDENCE, visual_relevance=HIGH, confidence=0.95
      visual_hint="cockroach on hotel bedsheet"

  F2: "Health department issued formal warning"
      category=RESULT, visual_relevance=MEDIUM, confidence=0.92
      visual_hint="official health department notice document"

  F3: "Travelers advised to avoid property"
      category=RESULT, visual_relevance=MEDIUM, confidence=0.88
      visual_hint="travel advisory overlay text"

  F4: "Sunset Palace Resort, Bali — 3-star rated"
      category=CONTEXT, visual_relevance=HIGH, confidence=0.90
      visual_hint="hotel exterior, Bali architecture"

Entities:
  people:       ["health inspector", "health department officer"]
  places:       ["Bali, Indonesia", "Sunset Palace Resort"]
  objects:      ["cockroach", "hotel room", "health certificate"]
  time_periods: ["2026-07-08"]
```

---

### Layer 2: CausalMeaningGraph output

```
Asset: cockroach_in_hotel_room
Facts: [F1, F2, F3]
Context: domain="travel_warning", tone="urgency"
WorldState: location="hotel_room", time_of_day="morning"

MeaningGraph {
  Nodes:
    N1: cockroach         (w=0.95, evidence="F1: infestation found")
    N2: unsanitary        (w=0.91, evidence="cockroach implies unsanitary")
    N3: health_risk       (w=0.87, evidence="unsanitary → health risk")
    N4: travel_warning    (w=0.84, evidence="F2: health dept warning") ← ROOT
    N5: avoid_destination (w=0.82, evidence="F3: travelers advised")

  Edges:
    N1 CAUSES:0.92    → N2
    N2 ESCALATES:0.88 → N3
    N3 ESCALATES:0.85 → N4
    N4 ENABLES:0.83   → N5

  rootNodeId: N4 (travel_warning)
  confidence: 0.91
  hasAmbiguity(): false  (no CONTRADICTS edges)
}

CinematicFunction: REVEAL
TensionLevel: 7.2
```

---

### Layer 3: GoalGraph + Planning output

**GoalGraph built by GoalDecomposer:**

```
GoalGraph {
  ROOT "Warn travelers about hotel safety" (priority=0.95)
    REQUIRES → INTERMEDIATE "Establish context" (priority=0.70)
                 REQUIRES → LEAF "Hotel exterior establishing shot" (maxShots=1)
    REQUIRES → INTERMEDIATE "Present evidence" (priority=0.90)
                 REQUIRES → LEAF "Cockroach close-up in room"       (maxShots=1)
                 REQUIRES → LEAF "Health department notice"          (maxShots=1)
    SUPPORTS → LEAF "Travel advisory recommendation"                 (maxShots=1)

  topoSort(): [hotel_exterior, cockroach_closeup, health_notice, travel_advisory]
  leaves():   4 leaf nodes → 4 shots
  totalShots(): 4
}
```

**PredictiveLearning query (before any render):**

```
predict(plan_candidate, context={domain: "travel_warning", audience: "news"})
→ PredictionResult {
    expectedCtr:            0.068  (6.8%)
    expectedWatchTime:      0.72
    expectedReviewScore:    0.84
    confidence:             0.73
    comparableProductions:  23
    basedOn: "23 travel_warning + OBSERVATIONAL + close-up productions"
  }
isReliable(): true  (confidence=0.73 >= 0.70, samples=23 >= 20)
```

**PlanObjectives (breaking_news preset):**

```
PlanObjectives {
  narrativeWeight: 0.30
  costWeight:      0.25
  latencyWeight:   0.35
  reviewScoreWeight: 0.10
  maxCostUsd:      $1.00
  maxLatencyMs:    15000
  minReviewScore:  0.70
}
```

**MultiObjectiveOptimizer selects plan:**

```
ShotSequencePlan {
  planId: "plan_travel_warning_001"
  goalGraph: GoalGraph above
  shots: [
    PlannedShot { position:1, subGoalId: hotel_exterior,
                  execution: { visualStrategy: OBSERVATIONAL, camera: {lens:50, stability:LOCKED} }
                  rationale: "Establishes location and context before revealing problem" }
    PlannedShot { position:2, subGoalId: cockroach_closeup,
                  execution: { visualStrategy: OBSERVATIONAL, camera: {lens:85, stability:LOCKED, movement:SLOW_PUSH} }
                  rationale: "REVEAL function: slow push into evidence builds tension" }
    PlannedShot { position:3, subGoalId: health_notice,
                  execution: { visualStrategy: URGENT, camera: {lens:50, stability:HANDHELD_SUBTLE} }
                  rationale: "Urgency shift: official response demands different energy" }
    PlannedShot { position:4, subGoalId: travel_advisory,
                  execution: { visualStrategy: OBSERVATIONAL, camera: {lens:50, stability:LOCKED} }
                  rationale: "Returns to calm observation for actionable recommendation" }
  ]
  goalConfidence: 0.88
  score: PlanScore {
    narrativeScore:      0.88
    estimatedCostUsd:    $0.48
    estimatedLatencyMs:  8200
    expectedReviewScore: 0.84
    composite:           0.82
  }
  meetsHardCaps(): true
}
```

---

### Layer 4: DirectorIntent (per shot, shown for Shot 2)

```
DirectorIntent {
  productionId:  "prod_20260708_hotel_bali"
  shotId:        "shot_002_cockroach"
  decisionDagId: "dag_prod_20260708_hotel_bali"

  meaning: MeaningContext {
    graph:             MeaningGraph (root=travel_warning, N1→N2→N3→N4)
    function:          CinematicFunction::REVEAL
    tensionLevel:      7.2
    meaningConfidence: 0.91
  }

  execution: ExecutionContext {
    attentionNode:   { must_show: ["cockroach", "bedsheet"], must_avoid: ["human_face"] }
    beat:            NarrativeBeat::EVIDENCE
    beatFacts:       [F1]
    visualStrategy:  VisualStrategy::OBSERVATIONAL
    styleRule:       { lens: 85, stability: "LOCKED", movement: "SLOW_PUSH", dof: "SHALLOW" }
    softConstraints: []
    sourceConfidence: 0.88
  }

  evaluation: EvaluationContext {
    priority:             ShotPriority::CRITICAL
    acceptanceThreshold:  0.75
    requiresFactVeto:     true
    requiredFacts:        [F1]
  }
}
```

---

### Layer 5: AFOS Compilation (Shot 2)

```
ShotGoalIR → 16 planners →

CameraIR {
  lens:      85
  stability: LOCKED
  movement:  SLOW_PUSH (0.2x speed)
  dof:       SHALLOW
  frame:     CLOSE_UP
}

PromptIR →
"Hyperrealistic. Natural anatomy, realistic proportions.
 Close-up: cockroach on white hotel bedsheet, Bali resort room, morning natural light.
 85mm. Static camera, shallow depth of field, slow subtle push.
 Broadcast news coverage. No text overlays, no logos."
 [char count: 241 / 2480 max ✓]
```

---

### Layer 6: FilmKernel + RenderPlugin

```
Tasks submitted to FilmKernel:
  Task { id: t1, type: RENDER, priority: CRITICAL, deadline: 15000ms, payload: RenderJob(shot1) }
  Task { id: t2, type: RENDER, priority: CRITICAL, deadline: 15000ms, payload: RenderJob(shot2) }
  Task { id: t3, type: RENDER, priority: IMPORTANT, deadline: 15000ms, payload: RenderJob(shot3) }
  Task { id: t4, type: RENDER, priority: FILLER,    deadline: 15000ms, payload: RenderJob(shot4) }

TaskScheduler.next(): CRITICAL tasks first → t1, t2 parallel → t3 → t4

RenderPlugin.execute():
  ProviderManager.select(): Kling v1.6 (lowest latency, $0.12/clip)
  CacheManager.hit(): miss (new prompts)
  Renders 4 × 5s clips

Results:
  shot1: scene_hotel_exterior.mp4   ($0.12, 1800ms)
  shot2: scene_cockroach_close.mp4  ($0.12, 2100ms)
  shot3: scene_health_notice.mp4    ($0.12, 1900ms)
  shot4: scene_travel_advisory.mp4  ($0.12, 2200ms)
  Total: $0.48, 8100ms wall clock (t1+t2 parallel = 2100ms critical path)
```

---

### Layer 7: Evaluation

```
EvaluationPlugin runs MultiAgentReview for each shot.
Shown for Shot 2:

FactReviewer (veto):
  Checks prompt against F1: "cockroach on bedsheet" ← F1 confirms ✓
  Checks for hallucinations: no weather, no crowd, no emotion added ✓
  PASS (score: 0.95, issues: [])

VisualReviewer:
  OBSERVATIONAL + LOCKED + 85mm for REVEAL → correct per VisualStrategyResolver ✓
  SLOW_PUSH appropriate for tension-building ✓
  PASS (score: 0.88)

NarrativeReviewer:
  Shot 2 (evidence) after Shot 1 (context) → correct sequence order ✓
  PASS (score: 0.90)

ConsensusVerdict (Shot 2):
  score: 0.91, accepted: true
  (FactReviewer pass = no veto triggered)

Overall (all 4 shots):
  ConsensusVerdict { score: 0.89, accepted: true }
```

---

### Layer 8: Learning

```
KnowledgeEvolution:
  Records pattern: travel_warning + cockroach + REVEAL + OBSERVATIONAL + 85mm → review_score=0.89
  PatternCandidate {
    supportCount:       24  (was 23)
    qualityCorrelation: 0.87
    confidence:         0.78  (computeConfidence(24, 0.87, 0.12))
    status:             'pending_test'  (not yet enough for auto-proposal)
  }

PredictiveLearning.calibrate():
  Updates model with { ctr: 0.071, watch_time: 0.74, review_score: 0.89 }
  Next prediction for same scenario: expectedCtr=0.071, comparableProductions=24

DAGRuntime.toDecisionDAG() → 21 nodes:
  FACT×4, MEANING×5, STRATEGY×1, INTENT×4, COMPILATION×4, RENDER×4, REVIEW×4, CONSENSUS×1
```

---

### Trace-back verification (Invariant 5)

Starting from `shot2: scene_cockroach_close.mp4`:

```
RENDER (shot2, conf=0.86)
  ↑ COMPILATION (CameraIR{lens=85,LOCKED}, conf=0.87) ←caused
COMPILATION (shot2)
  ↑ INTENT (DirectorIntent shot2, conf=0.88) ←caused
INTENT (shot2)
  ↑ STRATEGY (ShotSequencePlan, conf=0.89) ←caused
STRATEGY (plan)
  ↑ MEANING (MeaningGraph root=travel_warning, conf=0.91) ←caused
MEANING (travel_warning)
  ↑ FACT (F1: cockroach_infestation, conf=0.95) ←caused

Complete trace:
"85mm lens chosen because:
 → OBSERVATIONAL strategy (domain=travel_warning, function=REVEAL)
 → travel_warning meaning (confidence=0.91)
 → cockroach CAUSES unsanitary ESCALATES health_risk ESCALATES travel_warning
 → source: F1 'Cockroach infestation found in multiple guest rooms' (conf=0.95)"

Invariant 5: PASS — full trace from video to source fact, 0 broken edges.
```

---

## Part 3: Phase 1 Walking Skeleton

### Philosophy

Do not implement the full system. Build a thin, complete path through all 8 layers. Each component can be minimal — but the path must be unbroken. This validates that the architectural abstractions fit together before investing in individual module depth.

A walking skeleton is complete (crosses all boundaries) but shallow (each component does just enough). It is not a prototype — it is the same code path that will grow into production.

### Walking Skeleton scope

| Layer | Component | Phase 1 implementation |
|---|---|---|
| 1 Knowledge | FactGraph | Real: parse `facts_json` + `entities_json` from `ArticleFact` model. No mock. |
| 2 Meaning | CausalMeaningGraph | Real: `ContextualMeaningResolver` for 1 domain (travel_warning). Typed `CausalRelation`. |
| 3 Planning | GoalGraph + FilmPlanner | Real: `GoalDecomposer` with 1 template (travel_warning). `SubGoalPlanner` with 2 strategies (Camera, Motion). `SequenceOptimizer`. |
| 3 Planning | MultiObjectiveOptimizer | Real: all 4 objectives scored. `CostEstimator` and `LatencyEstimator` use fixed per-shot estimates ($0.12, 2000ms). |
| 3 Planning | PredictiveLearning | **Stub**: returns `PredictionResult::noPrior("Phase 1 — no data yet")`. Interface is real. |
| 4 Intent | DirectorIntent assembly | Real: `IntentAssembler` maps `PlannedShot` → `DirectorIntent`. |
| 5 Compilation | AFOS Compiler | Real: existing `GenerateArticleKlingCommand` refactored to receive `ExecutionContext`. |
| 6 Rendering | FilmKernel + RenderPlugin | Real: FilmKernel with TaskScheduler. RenderPlugin calls fal.ai Kling v1.6. |
| 7 Evaluation | MultiAgentReview | Real: FactReviewer (Claude Haiku, veto). VisualReviewer and NarrativeReviewer as **stubs** (return PASS). |
| 8 Learning | KnowledgeEvolution | **Stub**: `recordPattern()` logs to database, no hypothesis generation yet. |
| Cross | DAGRuntime | Real: `execute()` wrapper, `toDecisionDAG()`, `explain()`. No partial logging anywhere. |

### Phase 1 success criteria

All 5 must pass before Phase 1 is signed off:

**Criterion 1 — End-to-end run:**  
`php artisan filmos:run-golden-scenario` produces 4 rendered video clips for the "cockroach hotel" article without error.

**Criterion 2 — DAG completeness:**  
`$dag->nodes()` contains at minimum: 4 FACT nodes, 5 MEANING nodes, 1 STRATEGY node, 4 INTENT nodes, 4 COMPILATION nodes, 4 RENDER nodes. Zero orphan nodes (every node except FACT nodes has at least 1 parent edge).

**Criterion 3 — Full trace-back:**  
`$dag->explain('RENDER_shot2')` returns a chain that reaches a FACT node. No broken edges.

**Criterion 4 — Invariant checks pass:**  
Run `php artisan filmos:check-invariants --production=prod_golden_scenario`. Reports all 6 invariants as PASS.

**Criterion 5 — PlanScore populated:**  
`$plan->score` is non-null and `meetsHardCaps($objectives)` returns true.

### Phase 1 artisan commands

```
php artisan filmos:run-golden-scenario          # full pipeline, travel_warning domain
php artisan filmos:explain-shot {productionId} {shotId}  # trace from shot back to facts
php artisan filmos:check-invariants {productionId}       # validate all 6 invariants
```

### Files to create in Phase 1

```
app/Services/AI/FilmOS/
├── Kernel/
│   ├── FilmKernel.php
│   ├── TaskScheduler.php
│   ├── MemoryManager.php          (stub — canFit() always true in Phase 1)
│   ├── FilmTask.php
│   ├── TaskResult.php
│   └── Plugins/
│       └── RenderPlugin.php
├── Meaning/
│   ├── MeaningNode.php
│   ├── MeaningEdge.php
│   ├── MeaningGraph.php
│   ├── CausalRelation.php         (enum)
│   ├── MeaningResolver.php        (interface)
│   └── ContextualMeaningResolver.php
├── Planning/
│   ├── GoalNode.php
│   ├── GoalEdge.php
│   ├── GoalGraph.php
│   ├── GoalNodeType.php           (enum)
│   ├── GoalRelation.php           (enum)
│   ├── PlannedShot.php
│   ├── ShotSequencePlan.php
│   ├── PlanObjectives.php
│   ├── PlanScore.php
│   ├── FilmPlanner.php            (interface)
│   ├── GoalDecomposer.php
│   ├── SubGoalPlanner.php
│   ├── SequenceOptimizer.php
│   ├── MultiObjectiveOptimizer.php
│   ├── Strategies/
│   │   ├── DecisionStrategy.php   (interface)
│   │   ├── DecisionCandidate.php
│   │   ├── CameraStrategy.php
│   │   └── MotionStrategy.php
│   └── Estimators/
│       ├── CostEstimator.php      (stub: $0.12 per shot)
│       └── LatencyEstimator.php   (stub: 2000ms per shot)
├── Intent/
│   ├── MeaningContext.php
│   ├── ExecutionContext.php
│   ├── EvaluationContext.php
│   ├── DirectorIntent.php
│   └── IntentAssembler.php
├── DecisionDAG/
│   ├── DAGRuntime.php
│   ├── DAGNode.php
│   ├── DAGEdge.php
│   ├── DAGNodeType.php            (enum, add PLAN type)
│   └── DecisionDAG.php
├── Learning/
│   ├── PredictiveLearning.php     (interface)
│   ├── PredictionResult.php
│   └── StubPredictiveLearning.php (Phase 1: always returns noPrior())
└── Evaluation/
    └── Plugins/
        └── EvaluationPlugin.php   (Phase 1: FactReviewer real, others stub)

app/Console/Commands/
├── FilmOS/
│   ├── RunGoldenScenarioCommand.php
│   ├── ExplainShotCommand.php
│   └── CheckInvariantsCommand.php
```

---

## ADR Chain Summary

| ADR | Type | Status | Role |
|---|---|---|---|
| ADR-012 | Implementation | Accepted | 8-layer architecture and phase roadmap |
| ADR-013 | Semantic | Amended by ADR-014/015 | Meaning Architecture, 8 corrections |
| ADR-014 | Philosophy | Amended by ADR-015 | Graph-First OS, FilmKernel, FilmPlanner |
| ADR-015 | Execution | Active | Graph Execution Model, GoalGraph, CausalMeaningGraph, Multi-objective, PredictiveLearning |
| **ADR-016** | **Lock** | **Accepted** | **6 invariants, Golden Scenario, Phase 1 Walking Skeleton** |

No further foundational ADRs before Phase 6. Future ADRs document Phase-specific implementation decisions only.

---

## Future Directions (post-Phase 2 — evidence-based, not speculative)

These three concepts are noted here for future consideration. They are **NOT part of the current architecture**. They should only be designed after Phase 1 and Phase 2 have produced real implementation evidence.

### EntityGraph (after Phase 2)
Characters, people, objects, and brands as first-class entities with persistent identity across productions. "Mahomes" carries face, jersey, voice, biography, and history — independent of which renderer is used. Enables character consistency when switching from Kling to Veo to future models. All other graphs reference EntityGraph nodes rather than raw strings.

### EventGraph (after Phase 2)
Events are the narrative unit above Facts. A Fact is data ("touchdown scored"). An Event is the cinematic moment ("crowd erupts → commentator screams → slow-motion replay → player celebration"). EventGraph sits between FactGraph (Layer 1) and GoalGraph (Layer 3). Most narrative planning happens at Event granularity, not Fact granularity.

### Simulation (after Phase 2)
Planner currently selects the best plan via MultiObjectiveOptimizer scoring. A Simulation layer would allow the Planner to *run* candidate plans against a fast surrogate model before committing to render — similar to a chess engine evaluating positions. Plan A simulates → score. Plan B simulates → score. Choose before spending GPU budget. Requires a trained surrogate model, which requires Phase 1–2 production data.

**When to revisit:** If after Phase 2 any of the following appear in production, that is the signal to design the corresponding graph:
- EntityGraph: two shots of the same person look inconsistent
- EventGraph: GoalDecomposer templates become too repetitive or too rigid
- Simulation: MultiObjectiveOptimizer frequently selects plans with poor actual outcomes

---

## References

- ADR-015: All 6 architecture elements validated by Golden Scenario
- ADR-014: Principles 1–3 codified as Invariants 1–3
- ADR-015: Principle 4 codified as Invariant 2
- ADR-013: Fact-grounded pipeline (no hallucination) codified as Invariant 5
- ADR-015: PredictiveLearning codified as Invariant 6
