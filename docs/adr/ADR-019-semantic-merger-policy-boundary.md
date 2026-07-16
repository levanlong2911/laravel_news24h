# ADR-019 — SemanticMerger: the policy boundary between article facts and cinematic knowledge

**Status:** Proposed — design only. No code written. No component deleted.
**Date:** 2026-07-16
**Supersedes:** nothing. **Constrains:** any future refactor of `ScenePlanner`, `BeatFusionEngine`, `FilmOS\Prompting`.

This ADR records **boundaries, ownership and policy**. It deliberately does not name classes or
methods: the audit behind it showed that class names and structure churn heavily, while the
responsibility lines are what survive.

---

## 1. What the audit found

The production path is:

```
Article → SceneShotPlanner/ShotGrammarEngine → ShotDTO → ScenePlanner (21 planners)
        → SceneGraph → PromptAST → Kling
```

Four findings, each verified against the code, in the order they overturned the previous one:

1. **`ScenePlanningResult` carries 18 typed plans; SceneGraph reads 8.** The other 10 are not
   dropped by the assembler — they are fused into `timeline` as English prose before the container
   is built. Two (`eyeGuidance`, `emotionArc`) are dead: `BeatFusionEngine` takes `EyeGuidancePlan`
   as an unused parameter, and nothing in the codebase reads `emotionArc`.

2. **`BeatFusionEngine` contains no cinematic algorithm.** It is two English phrasebooks
   (`ATMOSPHERE_ACTIVE`, `EYE_IMPLICIT`), five sentence templates differing only in connectives,
   and a 12-word truncator. Remove the string-joining and nothing remains. Its 12-word chop is a
   budget reducer in disguise, placed in the Director and named so that nobody would fix it.

3. **The prose does not originate in `BeatFusionEngine`.** Most planners already return prose.
   But **every table is typed on the left of `=>` and prose only on the right**:
   `'aerial_vehicle' => ['hook' => 'before the subject below can be named']`. The semantic
   (`conceal_identity`) already exists — it is the key. Migrating "phrase → semantic" is therefore
   *reading the key instead of the value*, not inventing knowledge.

4. **The root cause is upstream of all of it.** `ShotDTO` carries the article's facts as three strings
   (`subActor`, `subAction`, `subObj`). There is no fact-extraction layer. Given `subObj = "yacht"`,
   a planner asked for foreground depth **has no option but to invent** "bow spray and wake foam".
   **The phrasebooks are a symptom, not the disease.**

The pattern repeats at three altitudes: each stage narrows its input, then the next stage invents
back what was just discarded. `ShotGrammarEngine` discards the article; planners invent prose.
Planners discard structure; `BeatFusionEngine` re-concatenates. `clause()` discards words.

**`ScenePlanner` is not a template engine.** It holds real algorithms — `DirectorPlanner`,
`ContinuityPlanner` (cross-shot state), `RhythmPlanner` (timing curves), `ActionPlanner`
(classification), `detectCategory()`. `DirectorPlanner`'s own docblock states this ADR's thesis
years early: *"produces structured camera and pacing intent that any renderer can read directly —
no inference required … across all providers (Kling, Veo, Seedance)."* That intent was correct and
was destroyed downstream.

**FilmOS did not reinvent ScenePlanner.** Every FilmOS concept already existed there, typed:
`PlanImportance` ≡ `camera_beats.weight` (*"1.0 = always apply; lower = apply if no conflict"*),
`CameraDirection.focus` ≡ `camera_beats.context`, `RenderPlan` ≡ DirectorPlanner's docblock,
`VisualStyle` ≡ `detectCategory()`. What FilmOS contributes is **the article's facts** and **the
discipline to stay typed all the way to the vendor**.

---

## 2. Three kinds of knowledge — never conflate them

| Kind | Test | Where it lives today |
|---|---|---|
| **Algorithm** | computes from input; owns a policy | DirectorPlanner, ContinuityPlanner, RhythmPlanner, ActionPlanner (classification), `detectCategory()`, `EyeGuidancePlan.anchor` |
| **Semantic model** | real knowledge that *happens to be* encoded as prose | CameraMotivationPlanner, CompositionPlanner, CompositionEvolutionPlanner, CuriosityPlanner, PhysicsPlanner |
| **Representation** | turns semantics into one vendor's words | BeatFusionEngine, `ATMOSPHERE_ACTIVE`, `EYE_IMPLICIT`, `clause()` |

> **A component is not a formatter merely because it returns prose.**
> If it holds knowledge or policy, it must be preserved through any refactor — only its *encoding*
> moves. `'to compress the entire world to a single point of will'` is not a string to delete; it is
> `camera_motivation: compress_attention` written in the wrong language.

This distinction is the single most expensive thing the audit produced. Losing it means a future
refactor deletes `CameraMotivationPlanner` as "just English".

---

## 3. Named artifacts — fix the terms before the ownership

"Article Truth" is an ambiguous phrase — it names both the article's facts and this shot's facts, and
this ADR deliberately never uses it. Those are different scopes with different owners, so they get
different names.

| Term | Scope | What it is |
|---|---|---|
| **Source Article** | article | the raw `articles` row — prose written for humans |
| **Article Model** | article | extracted, structured facts. Phase 1: human-authored `scenario.json`. Phase 2: an extractor. |
| **Shot Truth** | **shot** | the subset of the Article Model that Selection assigns to *this* shot |
| **Default semantics** | `(category, beat)` | the phrasebook's **key** side — a token the article did not supply |
| **Vocabulary** | `(category, token)` | the phrasebook's **value** side — words for a token |

```
Source Article → Article Model → [Selection] → Shot Truth → [Precedence] → RenderPlan
```

**Precedence reads Shot Truth, never the Article Model.** Selection is the only thing that ever sees
the whole article. This makes the boundary *testable*: Shot Truth is an inspectable artifact, so
"why did shot 2 say `vertical bow`?" has exactly one answer with exactly one owner.

The **Default semantics / Vocabulary** split is not new — it falls straight out of §1 finding 3.
The phrasebooks are already typed on the left of `=>` and prose on the right. The left side is a
token the article failed to supply; the right side is how to say a token in one vendor's words.
They are two different things that today happen to share one PHP array.

### 3.1 Input ownership

| Input | Scope | Owner | Phase 1 source |
|---|---|---|---|
| **Article Model** | **article** | (Phase 2) fact extraction | **human-authored `scenario.json`** |
| **Shot semantics** | shot | `ShotGrammarEngine` → `ShotDTO` | unchanged |
| **Cinematic plan** | shot / beat | `ScenePlanner` (Algorithm tier only) | unchanged |
| **Default semantics** | `(category, beat)` | phrasebook key side | read by **Precedence**, only when Shot Truth is silent |
| **Vocabulary** | `(category, token)` | phrasebook value side | read by the **Formatter**, never by the merger |

**The Article Model must not be denormalized into `ShotDTO`.** `grey hull` belongs to the article,
not to shot 1. Copying it into N shot DTOs duplicates the truth, destroys the single source, and —
worst — **pre-bakes the selection at DTO-build time, killing the policy before it can exist**.
`ShotDTO` stays small and shot-scoped; `ScenePlanner` never learns that an article exists.

**`scenario.json` is Author Truth, not the Source Article.** A human extracted it. The `moonrise`
render proves the *schema is expressive enough*; it does not prove extraction works. Phase 1 keeps
the human in that role deliberately (§8).

---

## 4. Output contract

The merger populates **`RenderPlan`** — the existing FilmOS IR. Contract, not shape:

- every payload is **typed**, **vendor-neutral**, and carries **no English**;
- every slot is **independently prunable** (the reducer drops whole items; it never truncates a
  sentence — `clause()`'s 12-word chop is deleted, not ported);
- a slot with no formatter **surfaces as an unhandled case**, never as a silently missing sentence.
  This is the property that would have prevented `eyeGuidance` and `emotionArc` from dying quietly.

New slots the Algorithm and Semantic tiers require: **DEPTH**, **CAMERA_INTENT**, **ATTENTION**,
**INFORMATION_STATE**, **REVEAL_MECHANISM**, **ATMOSPHERE**, **LIGHT**, **TIMING**.

**TIMING is knowingly unrenderable on the one-clip path** — a single Kling generation gives no
intra-clip timing control, so `RhythmPlanner`'s curves are metadata for the multi-shot path only.
Recorded here so its absence reads as a decision, not a bug.

---

## 5. Ownership matrix — exactly one owner per slot

No slot may have two owners. Two collisions are known and must be resolved before implementation:

- **DEPTH** — `CompositionPlanner` (per-shot, keyed by camera code) and `CompositionEvolutionPlanner`
  (per-beat, keyed by category) both produce depth. `CompositionPlanner`'s table is football-brained
  (*"field markings and turf texture"*, *"other players and team structure"*) — it says stadium for
  every WIDE shot of every subject. `CompositionEvolutionPlanner` exists **to rescue it**, not to
  corrupt it. Resolution: the category-aware source owns DEPTH; the camera-code table retires into
  vocabulary.
- **ENVIRONMENT / ATMOSPHERE / LIGHT** — FilmOS `ENVIRONMENT`, `PhysicsPlanner` atmosphere, and
  `VisualContrastPlan` light describe one region of meaning from three sources. They must be made
  disjoint before any of them is wired.

---

## 6. Policies owned by this boundary

**SemanticMerger (policy boundary)**

```
Responsibilities
----------------
1. Selection             Article Model → Shot Truth
2. Precedence            Shot Truth > Algorithm > Default semantics
3. Coverage              distribution of facts across the sequence   [deferred, §6.3]
4. RenderPlan population typed slots only

Non-responsibilities
--------------------
✗ prompt formatting          ✗ wording
✗ provider syntax            ✗ budget / reduction
✗ LLM prompting              ✗ article extraction
```

Named as a role. If the implementation later splits into several components
(`FactSelector`, `PrecedenceResolver`, `RenderPlanAssembler`), this ADR stays true.

### 6.1 Selection — *new; nothing in the codebase does this*

`ScenarioBootstrapper` reads `focus_node` from the JSON; `ScenarioLoader` validates it against the
beat's scene nodes. **The system enforces that the choice is consistent and never makes the choice.**

> **The scenario author is today's Selection Engine.**

This is the single largest finding of the audit, and it explains the benchmark/production quality gap
with **one mechanism and no further hypotheses**: the benchmark has a selection policy (a human), and
production has none. Selection is what replaces them — *HOOK gets `vertical bow`, not `five
satellite domes`.*

**Invariant — Selection may only reduce scope, never invent information.** It may choose `grey hull`
from the Article Model. It may not emit `sleek yacht`: `sleek` is Vocabulary's word, and `yacht` had
better be in the model. Selection subsets; it never paraphrases.

### 6.2 Precedence — *enforces "never fabricate"*

Shot Truth wins. Default semantics are consulted only where Shot Truth is silent.

**Fallback is a Precedence rule, not a property of the phrasebook.** Vocabulary does not know it is a
fallback and must never be described as "fallback-only" — it is a **total function from token to
words** and it always knows how to realize a DEPTH token. *Whether that token came from the article
or from a default is Precedence's decision, taken before the formatter is ever called.* Collapsing
these two makes the phrasebook responsible for a choice it cannot see.

### 6.3 Coverage — *the policy exists; its implementation is deferred past Phase 1*

Not "future", and not roadmap: **Coverage is already part of this architecture.** Only the code is
deferred. If five shots each greedily select the strongest fact, all five say `grey hull` and the
film never shows the satellite domes. Nothing is individually wrong and the whole is poor.
Distribution across the sequence is an **article-level policy** — not a formatter, not a reducer, not
a planner concern.

Recorded now so nobody later solves it inside a formatter. The same shape has already been solved
once, two layers down: `OrderPolicy` exists so the reducer walks a tier by order across the *whole*
plan instead of starving the last beat. A shape that appears twice independently is real.

---

## 7. The hallucination boundary

Two layers can fabricate, so the fence needs two rails:

> **Selection may only reduce scope, never invent information.**
> **Vocabulary may express, amplify, or fill silence — never create a fact.**

| Shot Truth | Output | Verdict |
|---|---|---|
| `grey hull` | "sleek grey hull emerging from mist" | ✅ cinematic enhancement — `sleek` is wording |
| *(silent on foreground)* | "cloud layer fills frame, thick white diffusion" | ✅ Precedence chose a default; the article said nothing |
| *(no domes in the model)* | "five radar domes" | ❌ **hallucination** — Vocabulary created a fact |
| `grey hull` in the model | Selection emits `sleek yacht` | ❌ **hallucination** — Selection paraphrased instead of subsetting |

With both rails, **no layer in the system has the right to invent**: Selection may only subset, the
Algorithm tier never sees the article, and Vocabulary may only word what it is handed.

This is the line between cinematic enhancement and fabrication, and it is why phrasebooks are
**demoted rather than deleted**. Deleting them leaves the prompt bare; letting them outrank the
article is how "bow spray and wake foam" got into a yacht no article ever described.

Precedence (§6.2) enforces the second rail; Selection (§6.1) enforces the first. This section is why
both exist.

---

## 8. Migration path — strangler, delete last

Nothing is deleted until it demonstrably has no callers. `SceneGraph`, `PromptAST` and `worker.py`
are live production (media_jobs has completed rows; the worker polls `/api/media-jobs/claim`) and are
untouched by Phases 1–3.

| Phase | Goal | Touches |
|---|---|---|
| **1 — Policy validation with a human-authored Article Model** | *If the Article Model is correct, does the rest hold?* Proves Selection + Precedence. **Does not** prove extraction. | new path only, parallel |
| **2 — Extraction** | an extractor replaces the human, feeding the *same* Article Model | extractor only |
| **3 — Payload parity** | FilmOS emits the payload `worker.py` already reads | no Python change |
| **4 — Cutover** | `video:generate-kling` switches; the old path loses its last caller | command wiring |
| **5 — Removal** | delete, with evidence of zero references | old prompt paths |

**Phase 1 and Phase 2 must not be merged.** Coupling two unproven things means a failure blames the
wrong one. And the split is itself the test of this boundary: **if replacing the human author with a
machine changes the merger by zero lines, the boundary was drawn correctly.**

**Planner disposition — nothing is deleted:**
- *Algorithm tier* — unchanged.
- *Semantic tier* — gains a token table alongside its prose; both coexist during migration.
- *Representation tier* — `BeatFusionEngine` stops being a pipeline stage in the new path only. Its
  phrasebooks move to the Kling formatter (they are good writing and they are vendor wording); its
  templates become beat formatting; its truncator is dropped in favour of the real reducer. It stays
  alive for the old path until Phase 4 removes its last caller.

**`CinematicCategory` is one concept with one source of truth.** `detectCategory()` decides;
scenario/benchmark may *override* when an author intends it, and may never invent. This merges
`category` ≡ `VisualStyle`, and it matters beyond tidiness: it is the lookup key of every phrasebook
in the system.

**Budget warning — a prerequisite, not a nicety.** The word budget is already saturated (the NFL
benchmark runs 207/200; CRITICAL alone once consumed the whole budget). Adding ~8 slots × 4 beats
*will* evict content, silently and one-for-one. Section budgets move from backlog to **prerequisite**
for Phase 1. Do not raise the budget — the pressure is what forces triage to mean anything.

---

## 9. Invariants

Implementation may change a hundred classes. If this table still holds, the architecture holds.

| Layer | Owns | Must never |
|---|---|---|
| **Article Model** | the article's facts | know about cameras, beats, or vendors |
| **Shot Truth** | this shot's facts | contain a fact the Article Model does not |
| **ShotDTO** | shot semantics | carry article facts |
| **Director** (Algorithm tier) | cinematic intent | know an article exists |
| **Default semantics** | a token for what the article omitted | outrank Shot Truth |
| **Vocabulary** | realization: token → words | create a fact, or decide whether it is used |
| **SemanticMerger** | selection + precedence + coverage | format, word, reduce, or invent |
| **RenderPlan** | typed IR | contain English or vendor syntax |
| **Reducer** | budget | truncate a sentence |
| **Formatter** | provider wording | see `ScenePlanningResult` |
| **Provider Adapter** | API syntax | make cinematic decisions |
| **Python Worker** | render execution | generate prompts |

```
Source Article ──(Phase 2: extraction)──┐     ⟵ Phase 1: human-authored
                                        ▼
                                  Article Model  (article-scoped)
                                        │
                                   [Selection]   ← may only subset
                                        ▼
                                   Shot Truth    (shot-scoped)
                                        │
ShotDTO (shot semantics) ───────────────┤
Director / Continuity / Rhythm ─────────┤     Algorithm — typed, unchanged
Default semantics (category, beat) ─────┤     consulted only where Shot Truth is silent
                                        ▼
                                  [Precedence]
                                        ▼
                      RenderPlan → Reducer → Formatter → Provider → worker.py
                                                  ▲
                              Vocabulary (category, token) ─┘   realization only
```

---

## 10. Deliberately not frozen

These are **semantic modelling questions, and this ADR does not decide them**. They resolve by
rendering benchmarks and looking, not by reasoning about code. Freezing them here would give a
guess the authority of a decision — which is precisely how `EYE_IMPLICIT` came to outrank a planner.

1. **ATTENTION vs CAMERA.focus** — `focus` names the subject; `anchor` names a feature of it
   (`velocity_blur`, `silhouette_edge`). They may merge; they may not. Overlapping slots are how one
   concept gets said twice and evicted once — but so is splitting a concept that was always one.
2. **`ActionPlanner.phases[].subject`** — the one prose field inside an Algorithm-tier planner. It
   may end up as a typed action timeline rather than prose. Not needed yet: Selection may make the
   question moot by supplying the subject from Shot Truth.
3. **Alignment** — does a production `ShotDTO` sequence map 1:1 onto the Article Model's beats, or
   does Selection also decide *how many* shots a fact deserves? (This borders on Coverage, §6.3.)
4. **`EyeGuidancePlan.anchor` vs `EYE_IMPLICIT`** — V2 replaced a hybrid planner (typed `anchor` +
   prose `instruction`) with a pure phrasebook, discarding the typed half along with the prose half.
   The likely resolution is `anchor` as the slot payload and V2's prose quality in the formatter,
   keyed by `(category, anchor)` — **but this needs a render to confirm, not a code review.**
