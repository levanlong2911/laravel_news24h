# Definition of Done (DoD)

**Date:** 2026-07-07  
**Status:** ACTIVE — applies to every task in every phase  
**Rule:** A task is NOT done until every applicable criterion is checked. "Code compiles" is not done.

---

## Universal criteria — every task, no exceptions

| # | Criterion | How to verify |
|---|-----------|---------------|
| U1 | PHPStan level max passes with zero errors | `vendor/bin/phpstan analyse --level=max` |
| U2 | No SCS import violations | Run the 4 grep checks in `dependency-graph.md` |
| U3 | No new circular dependencies | Check against dependency-graph.md Level map |
| U4 | All public methods have a unit test | `vendor/bin/phpunit --coverage-text` — coverage ≥ 90% per class |
| U5 | `final` on all concrete classes (unless extension is intentional) | Code review |
| U6 | No `public` mutable properties — use readonly or getters | Code review |
| U7 | No business logic in constructors — constructors only assign | Code review |

---

## Type A — Domain object (DTO / Value Object / Aggregate)

*Applies to: ProductionBible, FrozenProductionBible, CharacterDefinition, StyleModule, Location, etc.*

| # | Criterion |
|---|-----------|
| A1 | `fromArray()` and `toArray()` are inverse: `fromArray(toArray($x))` produces identical object |
| A2 | Immutability after lock: `with*()` on a locked object throws `\LogicException` |
| A3 | Mutation returns a new instance, never mutates `$this` |
| A4 | `toArray()` output contains no PHP objects — only scalars, arrays, null |
| A5 | Test with empty/null/boundary inputs (empty string, 0.0, [], PHP_INT_MAX) |
| A6 | No repository or service calls inside the class |

**Test file must contain:**
- Happy path construction
- fromArray → toArray round-trip
- Immutability invariant (mutation returns new instance)
- Lock/freeze guard (if applicable)
- Boundary inputs

---

## Type B — Repository

*Applies to: ProductionBibleRepository, CharacterStateRepository, etc.*

| # | Criterion |
|---|-----------|
| B1 | Interface lives in `FilmOS\Repositories\` — never the concrete class |
| B2 | Concrete implementation lives in `app\Repositories\Eloquent\` |
| B3 | `save()` is idempotent — calling twice does not create duplicate |
| B4 | `findById()` returns null (not exception) when not found |
| B5 | Domain objects returned, not Eloquent models |
| B6 | Integration test against real SQLite (not mocked): save → reload → assert equal |
| B7 | Migration runs cleanly on fresh DB: `php artisan migrate:fresh --env=testing` |
| B8 | Foreign key constraints match domain model |

**Test file must contain:**
- Integration test: save + reload
- Null return on missing ID
- Idempotency (double-save)
- Schema test (all expected columns exist)

---

## Type C — Engine / Evaluator

*Applies to: ConstraintEngine, VisualLanguageEngine, QualityEngine, etc.*

| # | Criterion |
|---|-----------|
| C1 | Stateless — no instance state modified between calls |
| C2 | `evaluate()` never throws — bad input returns a report with violations, not an exception |
| C3 | At least one test per rule that verifies the rule BLOCKS a known-bad input |
| C4 | At least one test that verifies valid input produces no blockers |
| C5 | Empty input (null fields, empty arrays) does not crash |
| C6 | Performance: `evaluate()` completes in < 10ms for typical input (no external calls) |

**Test file must contain:**
- One failing case per constraint rule
- One passing case per constraint rule  
- Empty/null input safety test
- Determinism test (same input → same output on two calls)

---

## Type D — Builder / Factory

*Applies to: PlanningContextBuilder, SimpleCameraBuilder, SimpleCompositionBuilder, etc.*

| # | Criterion |
|---|-----------|
| D1 | Returns a fully-populated object — no null fields unless nullable by design |
| D2 | Output is deterministic for same input (no randomness unless seeded by shotId) |
| D3 | All input combinations in the decision table are tested |
| D4 | Builder does not call external services or repositories |
| D5 | `build()` completes in < 5ms |

**Test file must contain:**
- One test per branch in the decision table
- Determinism test (call twice, assert equal)
- Boundary test (min/max energy, 0.0 scale, empty viewerShouldNotice)

---

## Type E — Stage / Pass (AFOS pipeline step)

*Applies to: Tier1Stage, Tier3Stage, KlingPromptPlanningPass, BackendStage, etc.*

| # | Criterion |
|---|-----------|
| E1 | `run(PipelineState)` returns a new PipelineState — never mutates input |
| E2 | Throws `\LogicException` (not generic `\Exception`) if phase precondition fails |
| E3 | Error message names the expected phase and the actual phase |
| E4 | Writes trace record if `$state->trace !== null` |
| E5 | Output IR fields are non-null after the stage runs |
| E6 | Domain method is private and accepts typed IR inputs only (no PipelineState) |

**Test file must contain:**
- Happy path: run stage, assert output IR is non-null
- Wrong phase guard test
- Trace record test (when TraceCollector is injected)

---

## Type F — Interface / Contract

*Applies to: BackendInterface, PromptPlannerInterface, ConstraintRule, ProductionEvent, etc.*

| # | Criterion |
|---|-----------|
| F1 | Interface has ≤ 5 methods (single responsibility) |
| F2 | Return types are concrete value types, not arrays |
| F3 | No implementation details leak into the interface |
| F4 | At least one fake/stub implementation tested in another test |
| F5 | Documented in SCS if it's a cross-boundary contract |

---

## Type G — Milestone (end of B1, B2, B3...)

*Applies to: end of each numbered task group in Phase-B.md*

| # | Criterion |
|---|-----------|
| G1 | All tasks in the milestone pass their individual DoD |
| G2 | `php artisan test` passes with zero failures |
| G3 | `php artisan migrate:fresh --seed --env=testing` succeeds |
| G4 | Dependency graph violations: zero (run all grep checks) |
| G5 | End-to-end smoke test: run Article → Video pipeline, no exception thrown |
| G6 | No dead code: classes added in this milestone are actually used |

**Milestone = merge to main. Never merge a half-done milestone.**

---

## DoD Quick Reference Card

```
Task type        | Key checks to never skip
-----------------+---------------------------------------------------------
Domain object    | round-trip, immutability, no repo calls
Repository       | integration test (real DB), idempotency, null return
Engine           | one fail-case per rule, no throw on bad input
Builder          | all decision branches tested, deterministic
Stage (AFOS)     | phase guard, trace record, domain method is private
Interface        | ≤ 5 methods, no impl details, tested via fake
Milestone        | all tasks done, zero test failures, E2E smoke test
-----------------+---------------------------------------------------------
Universal (all)  | PHPStan max, SCS compliance, no circular deps, ≥90% cov
```

---

## What "90% coverage" means here

Coverage is measured per class, not per file or per package.

- `ProductionBible` class: ≥ 90% line coverage
- `SimpleCompositionBuilder` class: ≥ 90% branch coverage (use `--coverage-text`)
- Test files themselves are excluded from coverage measurement

Coverage ≥ 90% does NOT mean the tests are good — it means they exist. Pair with the type-specific criteria above.

---

## Milestone release process

After every completed milestone (B1, B2, B3...):

1. Run full test suite: `php artisan test` — must be green
2. Run PHPStan: `vendor/bin/phpstan analyse --level=max` — must be zero errors
3. Run dependency violation check (all 4 grep commands from dependency-graph.md)
4. Run E2E smoke test: trigger Article → Video pipeline
5. Merge to `main`
6. Tag: `git tag phase-B1-done`
7. Start next milestone

**Do not accumulate multiple milestones before merging. Merge each one independently.**
