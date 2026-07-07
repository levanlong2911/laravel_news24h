# Architecture Decision Records

This directory documents significant architectural decisions for the AI Filmmaking OS project.

## Governance documents

| Document | Purpose |
|----------|---------|
| [Architecture Freeze v1](../architecture-freeze-v1.md) | Freeze declaration, phase sequencing, vertical slice mandate |
| [System Contract Specification v1](../scs-v1.md) | Boundary contracts, naming conventions, import rules |
| [Dependency Graph](../dependency-graph.md) | Module-level dependency map, forbidden imports, circular dep watchlist |
| [Definition of Done](../definition-of-done.md) | Per-task-type DoD (Domain / Repo / Engine / Stage / Milestone) |
| [Roadmap / Phase A.5](../roadmap/Phase-A5.md) | **START HERE** — Kling API client, first real video (3–4 days) |
| [Roadmap / Phase B](../roadmap/Phase-B.md) | FilmOS Core — 8 weeks, 35 tasks with dependencies |
| [Runtime Design](../runtime/Runtime.md) | Production Orchestrator — Scheduler, Checkpoint, Retry, Cancellation |

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-001](ADR-001-freeze-afos-compiler-core.md) | Freeze AFOS Compiler Core v1 | Accepted |
| [ADR-002](ADR-002-filmos-unified-model.md) | FilmOS Unified Model | Proposed |
| [ADR-003](ADR-003-filmos-extended-engines.md) | FilmOS Extended Engines | Proposed |
| [ADR-004](ADR-004-production-event-bus.md) | Production Event Bus | Proposed |
| [ADR-005](ADR-005-persistence-model.md) | Persistence Model | Proposed |
| [ADR-006](ADR-006-filmos-runtime-architecture.md) | FilmOS Runtime Architecture | Proposed |
| [ADR-007](ADR-007-capability-resolution.md) | Capability Resolution & Backend Abstraction | Draft |
| [ADR-008](ADR-008-asset-graph.md) | Asset Graph & World Graph | Draft |
| [ADR-009](ADR-009-knowledge-os.md) | KnowledgeOS / Ontology Engine | Draft |
| [ADR-010](ADR-010-decision-engine.md) | Decision Engine & Quality Optimization | Draft |
| [ADR-011](ADR-011-news-video-pipeline.md) | News Video Pipeline — Graph Orchestration | Superseded |
| [ADR-012](ADR-012-filmos-incremental-architecture.md) | FilmOS Incremental Architecture v1.0 | Accepted |
| [ADR-013](ADR-013-filmos-meaning-layer.md) | FilmOS Architecture v2.0 — The Meaning Layer | Draft |

**Amendment log:**
- ADR-002 Amendment A — RenderContext parallel path to backend
- ADR-002 Amendment B — ProductionBible Module Pattern (anti-God-Object)
- ADR-002 Amendment C — PlanningContext decomposition (ShotContext/VisualContext/CharacterContext/MotionContext/EditingContext)
- ADR-002 Amendment D — Extended ConstraintEngine (8 constraints: +Semantic/Camera/Lighting/Emotion)
- ADR-003 Amendment E — VisualMemory sub-types (AppearanceMemory/SpatialMemory/LightingMemory/CompositionMemory/AssetMemory)
- ADR-003 Amendment F — EditingOS as independent namespace
- ADR-006 — FilmOS Runtime Architecture: SemanticGraph, WorldStateEngine, DirectorOS, QualityEngine, BudgetEngine, PromptIntelligence, AssetDependencyGraph, PluginRegistry
- ADR-007 — Capability-first architecture: CapabilitySpec + CapabilityResolver supersede model-first ProviderSelector
- ADR-008 — WorldGraph (containment/interaction/state) distinct from AssetDependencyGraph (invalidation)
- ADR-009 — KnowledgeOS: ontology-based inference layer below FilmOS (IS-A, COVERS, HIDES, REQUIRES)
- ADR-010 — DecisionEngine: tournament optimization loop; QualityEngine becomes scorer inside it; ShotDecided replaces VideoRendered
- ADR-011 — News Video Pipeline V7: Graph Orchestration (FactGraph → EventGraph → NarrativeGraph → ViewerIntentGraph → AFOS); superseded by ADR-012
- ADR-012 — FilmOS Incremental Architecture v1.0: 5-layer system (Knowledge → Decision → Compilation → Rendering → Learning); Decision Ledger as cross-cutting foundation from Phase 1; Confidence Propagation (decay per step, gate at 0.70/0.60); ConstraintEngine (MUST NOT, distinct from DomainStyleProfile SHOULD); 6-phase implementation roadmap; DecisionReplay closes analytics learning loop

---

## System Architecture

```
┌────────────────────────────────────────────────────────────────┐
│              P R O D U C E R   A I  (Phase G)                  │
│        Article → Research → BudgetEngine → Publish             │
├────────────────────────────────────────────────────────────────┤
│    D I R E C T O R   O S  +  D E C I S I O N  (ADR-006/010)   │
│  DirectorOS → DirectorPlan · EmotionCurve · BlockingPlan        │
│  SemanticGraph · WorldStateEngine · WorldGraph (ADR-008)       │
│  DecisionEngine: tournament → RefinementEngine → ShotDecided   │
├────────────────────────────────────────────────────────────────┤
│       P L A T F O R M   I N T E L L I G E N C E  (ADR-006)    │
│  PluginRegistry (RendererPlugin · TTSPlugin · EditorPlugin)    │
│  QualityEngine (scorer) · PromptIntelligence · FilmKnowledgeBase│
│  AssetDependencyGraph (invalidation cascade)                   │
├────────────────────────────────────────────────────────────────┤
│  C A P A B I L I T Y   R E S O L U T I O N  (ADR-007)         │
│  CapabilitySpec → CapabilityResolver → CapabilityCatalog       │
│  ProviderProfile (Kling · Veo · Runway · Flux · Imagen · ...)  │
│  [No provider names in FilmOS — capability-first, not model-first]│
├────────────────────────────────────────────────────────────────┤
│                  EDITING OS  (Phase F)                         │
│    EDL → RhythmPlanner → BeatAligner → FCP/DaVinci Export      │
├────────────────────────────────────────────────────────────────┤
│         V I S U A L   M E M O R Y  (Phase E)                  │
│  AppearanceMemory · SpatialMemory · LightingMemory             │
│  CompositionMemory · AssetMemory  → RetrievedMemory            │
├────────────────────────────────────────────────────────────────┤
│       C H A R A C T E R   I N T E L L I G E N C E  (D)       │
│  CharacterBrain → BehaviorPlanner → ActingGraph                │
│  MotionLibrary → DomainMotionProfile × 5 domains               │
├────────────────────────────────────────────────────────────────┤
│        V I S U A L   L A N G U A G E  (Phase C)               │
│  LensBible · LightingBible · CompositionBible                  │
│  MovementBible · ColorBible · FocusBible · TransitionBible     │
│  → VisualGrammar → VisualLanguageEngine.validate(CameraIR)     │
├────────────────────────────────────────────────────────────────┤
│              F I L M O S   C O R E  (Phase B)                 │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │  ProductionBible (root aggregate)                        │  │
│  │   ├── WorldModule      ← World definitions              │  │
│  │   ├── CharacterModule  ← CharacterDefinition + State    │  │
│  │   ├── AssetModule      ← AssetDefinition + Instance     │  │
│  │   └── StyleModule      ← 7 Bibles                       │  │
│  │  ConstraintEngine (8 constraints) + KnowledgeOS (ADR-009)│  │
│  │  SceneGraph v2 (SceneNode → ShotNode)                   │  │
│  │  PlanningContext (ShotCtx + VisualCtx + CharCtx + ...)  │  │
│  └─────────────────────────────────────────────────────────┘  │
├────────────────────────────────────────────────────────────────┤
│           A F O S   C O M P I L E R   v1  [FROZEN]            │
│  ShotGoalIR → CompositionIR → CameraIR → PromptIR              │
│                          ↑                                     │
│              RenderContext (parallel path, Amendment A)        │
├────────────────────────────────────────────────────────────────┤
│  K N O W L E D G E O S   (ADR-009) — cross-cutting            │
│  OntologyRegistry · InferenceEngine · InferredFact             │
│  Enriches: ConstraintEngine · RenderContext · PlanningContext  │
├────────────────────────────────────────────────────────────────┤
│  PRODUCTION EVENT BUS (ADR-004) · PERSISTENCE (ADR-005)        │
│  Events: ShotDecided (ADR-010) · ScenePlanned · BibleLocked    │
│  Tables: +decision_records +world_graphs +capability_catalog   │
└────────────────────────────────────────────────────────────────┘
```

---

## Phase Roadmap

```
Phase A  [DONE]    Freeze AFOS v1 (ADR-001)

Phase B  [NEXT]    FilmOS Core (ADR-002 + ADR-005 tables)
         B1  ProductionBible (Module Pattern) + StyleBible + migrations
         B2  WorldModel + WorldModule
         B3  CharacterDefinition + CharacterState + CharacterModule
         B4  AssetDefinition + AssetInstance + AssetModule
         B5  ConstraintEngine (8 built-in constraints)
         B6  SceneGraph v2 (SceneNode + ShotNode)
         B7  PlanningContext (decomposed) + PlanningContextBuilder
         B8  Wire into GraphAssembler + production_events (ADR-004)

Phase C  ⭐        Visual Language Engine (ADR-003)
         C1  7 Bibles in StyleModule
         C2  VisualGrammar + VisualLanguageEngine
         C3  CameraIR validation hook post-AFOS

Phase D  ⭐⭐⭐    Character Intelligence (ADR-003)
         D1  CharacterBrain + BehaviorPlanner
         D2  ActingGraph + ActingLibrary (8 core emotions)
         D3  ActingEngine → CharacterContext enrichment
         D4  MotionLibrary + DomainMotionProfile × 5

Phase E  ⭐⭐⭐⭐  Visual Memory (ADR-003 Amendment E + ADR-005)
         E1  AppearanceMemory + AssetMemory (in-memory)
         E2  SpatialMemory + LightingMemory + CompositionMemory
         E3  JsonSnapshotStore (persistent)
         E4  pgvector + semantic embeddings

Phase F  ⭐⭐⭐⭐⭐ EditingOS (ADR-003 Amendment F)
         F1  EDL model + MasterTimeline
         F2  RhythmPlanner (narrative → cut timing)
         F3  BeatAligner (music sync)
         F4  LensDriftReport from CompositionMemory
         F5  Exporters: DaVinci · FinalCut · Premiere

Phase G  ⭐⭐⭐⭐⭐ Runtime Architecture (ADR-006)
         G1  SemanticGraph (CharacterRole/Conflict/Payoff/Foreshadow)
         G2  WorldStateEngine (object state tracking across shots)
         G3  DirectorOS (DirectorPlan + EmotionCurve + BlockingPlan)
         G4  EditingIntelligence (autonomous EDL decisions + LensDriftReport)
         G5  QualityEngine (scorer — used inside DecisionEngine)
         G6  BudgetEngine + BudgetEnvelope (cost-per-shot optimization)
         G7  FilmKnowledgeBase (reference DB: directors/cinematographers/genres)
         G8  PromptIntelligence (1000+ render records → pattern extraction)
         G9  AssetDependencyGraph (change propagation + invalidation cascade)
         G10 PluginRegistry (RendererPlugin · TTSPlugin · EditorPlugin)

Phase H  ⭐⭐⭐⭐⭐ Platform Maturity (ADR-007 · ADR-008 · ADR-009 · ADR-010)
         H1  CapabilitySpec + CapabilityResolver (ADR-007)
              → CapabilityCatalog (config-driven provider profiles)
              → Supersedes ProviderSelector
         H2  WorldGraph (ADR-008)
              → AssetNode + RelationshipType + StateVector
              → StateTransitionRules + propagation
              → production_world_graphs table
         H3  KnowledgeOS (ADR-009)
              → OntologyRegistry (PhysicalObject / Clothing / Weapon / Light)
              → InferenceEngine (forward-chaining, max depth 4)
              → Enrich ConstraintEngine + RenderContext + PlanningContext
         H4  DecisionEngine (ADR-010)
              → TournamentDecisionEngine (N parallel candidates)
              → RefinementEngine (FailureTargetedRefinement)
              → DecisionBudget.forPriority (CRITICAL / IMPORTANT / FILLER)
              → ShotDecided event replaces VideoRendered as canonical signal
              → production_decision_records table
```

---

## Completion Estimate

| Layer | After B | After C | After D | After E+F | After G | After H |
|-------|---------|---------|---------|-----------|---------|---------|
| AFOS Compiler | 98% | 98% | 98% | 98% | 98% | 98% |
| FilmOS Core | 85% | 88% | 92% | 95% | 98% | 99% |
| Visual Language | 0% | 90% | 90% | 95% | 98% | 99% |
| Character Intelligence | 20% | 20% | 85% | 90% | 95% | 97% |
| Visual Memory | 0% | 0% | 0% | 85% | 92% | 95% |
| Editing AI | 0% | 0% | 0% | 80% | 90% | 93% |
| Semantic / Director AI | 0% | 0% | 0% | 0% | 90% | 95% |
| Quality / Budget / Plugin | 0% | 0% | 0% | 0% | 88% | 98% |
| Capability Resolution | 0% | 0% | 0% | 0% | 0% | 99% |
| WorldGraph + KnowledgeOS | 0% | 0% | 0% | 0% | 0% | 90% |
| Decision Engine | 0% | 0% | 0% | 0% | 0% | 97% |
| **Video quality vs. sample** | **70%** | **80%** | **88%** | **94–96%** | **99%** | **99.5%** |
| **Long-term scalability** | 40% | 50% | 60% | 75% | 90% | **99.5%** |

---

## Key Design Principles

1. **AFOS is frozen** — compiler only, no business logic (ADR-001)
2. **ProductionBible = root aggregate, not God Object** — logic lives in Modules (ADR-002 Amendment B)
3. **ShotGoalIR stays small** — world/character travel via RenderContext, not through AFOS (ADR-002 Amendment A)
4. **PlanningContext = aggregate of sub-contexts** — ShotContext / VisualContext / CharacterContext / MotionContext / EditingContext (ADR-002 Amendment C)
5. **ConstraintEngine prevents, ContinuityEngine checks** — 8 constraints block impossible states before AI calls (ADR-002 Amendment D)
6. **VisualMemory = 5 specialized stores** — each with its own retrieval strategy (ADR-003 Amendment E)
7. **EditingOS is independent** — not coupled to FilmOS, can export to FinalCut/DaVinci/Premiere (ADR-003 Amendment F)
8. **Event-driven pipeline** — retry, resume, parallel, observable (ADR-004)
9. **Simplest storage per object** — not one-size-fits-all (ADR-005)
10. **Capability-first, not model-first** — FilmOS describes what it needs (CapabilitySpec), never which model to use; adding a new AI model = one config entry + one plugin (ADR-007)
11. **WorldGraph ≠ AssetDependencyGraph** — containment/interaction graph (ADR-008) and invalidation graph (ADR-006) are separate concerns with separate traversal strategies
12. **Bible is data, KnowledgeOS is inference** — ontology derives implicit constraints that static data cannot express; IS-A / COVERS / HIDES / REQUIRES rules run before every AI call (ADR-009)
13. **Quality is a system property, not a model property** — DecisionEngine owns the optimization loop; QualityEngine is just the scorer; models are interchangeable inside the loop (ADR-010)

---

## What is an ADR?

An Architecture Decision Record captures a significant architectural decision:
the context, the decision, and the consequences. Status meanings:

- **Accepted** — in effect, implementation follows this decision
- **Proposed** — design finalized, implementation not yet started
- **Amended** — base decision stands, amendments modify specific sections
- **Superseded** — replaced by a later ADR (linked)
