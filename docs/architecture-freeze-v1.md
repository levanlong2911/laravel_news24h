# Architecture Freeze v1

**Date:** 2026-07-07  
**Status:** ACTIVE  
**Declared by:** Project Lead

---

## Declaration

The AI Filmmaking OS architecture is sufficiently mature to begin implementation.  
Further ADR writing has diminishing returns. The constraint shifts from design to delivery.

**From this date: design energy goes into code, not documents.**

---

## Freeze Levels

### Level 1 — Hard Freeze (ADR-001 → ADR-005)

> Foundation. No changes without a documented architecture defect.

| ADR | Title | Status |
|-----|-------|--------|
| ADR-001 | Freeze AFOS Compiler Core v1 | Accepted — FROZEN |
| ADR-002 | FilmOS Unified Model | Proposed — FROZEN |
| ADR-003 | FilmOS Extended Engines | Proposed — FROZEN |
| ADR-004 | Production Event Bus | Proposed — FROZEN |
| ADR-005 | Persistence Model | Proposed — FROZEN |

**Rule:** If implementation reveals a design defect in these ADRs, open an Amendment (Amendment X format), do not rewrite the ADR. Amendments are additive, not destructive.

### Level 2 — Evolve-in-Place (ADR-006 → ADR-010)

> Platform maturity. Allowed to evolve as real integration data arrives.

| ADR | Title | Status |
|-----|-------|--------|
| ADR-006 | FilmOS Runtime Architecture | Proposed — evolve permitted |
| ADR-007 | Capability Resolution & Backend Abstraction | Draft — evolve permitted |
| ADR-008 | Asset Graph & World Graph | Draft — defer until Phase G |
| ADR-009 | KnowledgeOS / Ontology Engine | Draft — defer until Phase H |
| ADR-010 | Decision Engine & Quality Optimization | Draft — defer until Phase H |

**Rule:** Amendments to ADR-006/007 must be logged in `docs/adr/README.md` amendment section. ADR-008/009/010 are not implemented before Phase G/H — do not let them block Phase B.

---

## What Was Discovered at Freeze Time

Before freezing, a code audit revealed that **the vertical slice MVP already exists in code**:

```
Article
  ↓ StoryPlanner (exists)
  ↓ SceneShotPlanner (exists)
  ↓ ScenePlanner (exists)
  ↓ GraphAssembler → ShotGoalIRAdapter (exists)
  ↓ AfosPassManager::defaults()->compile() (exists, 517 tests)
  ↓ KlingBackend::serialize() → prompt string (exists)
  ↓ [Kling HTTP API] ← MISSING
  ↓ Video URL
```

**The entire pipeline is wired. Only the Kling API HTTP call is missing.**

This means Phase A.5 (ship first video) is achievable in days, not weeks.  
Phase B (FilmOS Core) adds quality, not existence.

---

## Phase Sequencing (Revised)

```
Phase A.5  [IMMEDIATE]   Kling API client + job dispatch + store video URL
                         → First real video from existing pipeline

Phase B    [NEXT]        FilmOS Core (ProductionBible, WorldModule, CharacterModule...)
                         → Multi-shot consistency, character tracking, style bible

Phase C    [AFTER B]     Visual Language Engine
Phase D    [AFTER C]     Character Intelligence
Phase E    [AFTER D]     Visual Memory
Phase F    [AFTER E]     EditingOS
Phase G    [AFTER F]     Runtime Architecture (DirectorOS, SemanticGraph...)
Phase H    [AFTER G]     Platform Maturity (CapabilitySpec, DecisionEngine...)
```

---

## Vertical Slice Mandate

Do not build complete horizontal layers before validating end-to-end.  
After each phase, the system must be able to run a video from Article to Publish.

```
After A.5:  1 article → 1 video (basic quality)
After B:    1 article → 1 video (consistent characters + style)
After C:    1 article → 1 video (cinematically intentional framing)
After D:    1 article → 1 video (character acting is coherent)
After E+F:  1 article → edited multi-shot video with rhythm
After G:    1 article → cinematically directed video (director decisions)
After H:    1 article → platform-quality video (optimization + cost control)
```

---

## How to Propose a Change Post-Freeze

1. Open a GitHub issue titled `[ARCH] <short description>`
2. Describe: which ADR is affected, what the implementation revealed, proposed amendment
3. If Level 1: requires explicit approval before proceeding
4. If Level 2: log the amendment in `docs/adr/README.md` and proceed
5. Never modify existing ADR body — add an Amendment section at the bottom
