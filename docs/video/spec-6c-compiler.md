# Phase 6C · Compiled Shot Contract · Compiler Acceptance

**Status:** the data contract of Phase 6C (strict prompt compilation, spec v6.3) is
implemented and covered by isolated tests and committed contract fixtures. The
compiler itself is **not built**: nothing compiles, freezes or renders a shot
through this contract yet, and no model is called.
**Scope:** the spec v6.3 library (`ai_phases`, `ai_json_schemas`,
`ai_phase_schemas`) and its contract layer. The production prompt path of the
current pipeline is unchanged.

Lines marked **[VERIFIED]** are read from the code. Lines marked
**[DECIDED 2026-10-21]** were approved for this round. Lines marked
**[NOT BUILT]** are acceptance criteria for the compiler, not features.

---

## 0. Version register

| Item | Value | Where |
|---|---|---|
| Spec | v6.3, `resources/ai/superyacht/spec_v6_3_en.md` sha `b0d2f051…04ed4` | `video:spec-import` |
| Schemas | `superyacht/*@6.3.0`, 8 schemas, never edited for task rules | `ai_json_schemas` |
| Action `spec.prompt_compile` | `contract_version` 2 | `config/video.php` → `superyacht.contracts` |
| Action `spec.design` | `contract_version` 2 (lock and review records added) | same |
| Action `spec.shot_list` | `contract_version` 1 | same |
| Contract compiler | `spec-contract-3` | `ResolvedPhase::COMPILER_VERSION` |
| Envelope validator | `envelope-6.3.0-1` | `EnvelopeValidator::VERSION` |

Changing a slot contract (`items`, `match`, pointer, mode, schema, required)
means editing the action in config **and** raising its `contract_version`. The
manifest carries the whole action, so the bundle hash changes with it. A
manifest built by another compiler version is refused on replay, never
re-interpreted.

## 1. The contract **[VERIFIED]**

- **[DECIDED 2026-10-21]** Phase 6C is a compiler: code assembles and validates;
  the spec allows a model only for the free action slot and the semantic judge
  (spec lines 1820 and 2737).
- **[DECIDED 2026-10-21]** One task compiles **one shot**. The output is still
  the data-contract list `data.compiled_shots[]` (spec line 593), holding exactly
  one CompiledShot. The 6C Output example in the spec is the shape of that one
  item (schema 5).
- Output slot `compiled_shots`: `superyacht/CompiledShot@6.3.0`, pointer
  `/data/compiled_shots`, `each_item`, required,
  `items = {count: 1, key: shot_id}` (`ActionContract::normalise`).
- Records, checked on their own payload through `ResolvedPhase::validateSlot`,
  never on the CompiledShot envelope:
  - `lock/spec`: `LockRecord@6.3.0`, whole record, required,
    `match /lock_type ∈ [spec]`;
  - `review/decision`: `ReviewDecision@6.3.0`, whole record, optional,
    `match /item_type ∈ [compiled_shot]`. Optional means the review may not
    have happened; a review record that is given is always checked in full.
- `match` compares with strict types; a missing field is an error.
- Task context (`TaskContext`): project, `input_key = shot_id`, shot id,
  ShotSpec revision and hash. It is fixed when the bundle is resolved and stored
  in the manifest; `checkOutput()` takes no other context. An action keyed by
  `shot_id` cannot be resolved without one.
- `checkOutput()` checks, in order: the envelope (phase `6C`, the bound
  project, status), the list, each item against schema 5, the item count, and
  that every `shot_id` equals the shot of the task context. Passing these checks
  is a structural result only; it does not make a shot fit to freeze.
- `executor` is one of `none | code | ai`; a model profile is required only for
  `ai` and refused for the others. `spec.prompt_compile` runs as `none`.

## 2. Limits of this round **[VERIFIED]**

- The task context is supplied by the caller or by a fixture. Nothing here
  proves that the ShotSpec revision and hash exist in a production source; they
  are recorded for lineage only.
- `CompiledShot.sources` is **not** compared with locked revisions here; that
  is invariant INV-CS-01 and belongs to the compiler (§3).
- Invariants (INV-CS-01…03, INV-SS-04 and the rest) still run in their own
  layer: `video:spec-check-fixtures`.
- No stage of the current pipeline stores a ShotSpec. The storyboard of the
  current pipeline is **not** a ShotSpec and is not converted into one.

## 3. Compiler acceptance criteria **[NOT BUILT]**

The compiler is accepted only when all of these hold:

1. **Inputs from locked revisions only:** the ShotSpec of the shot, the
   CanonicalDesign with its build states, the constraint registry, the reference
   pack, the location or set, style and routing, the operational context and the
   locked duration. Missing input stops the task; nothing is inferred, converted
   or filled in, and no design is read back from an anchor prompt.
2. **Task context from the source, not from the output:** the orchestrator
   builds the `TaskContext` from the chosen ShotSpec row and stores it with the
   run record before any call.
3. **One task per shot:** the orchestrator fans out one task per shot and
   collects the results; no single call writes the whole film.
4. **Checks before freeze**, all of them, in this order: envelope and
   CompiledShot schema; invariants INV-CS-01 (sources point to locked revisions),
   INV-CS-02 (constraint status matches state), INV-CS-03 (only present parts are
   named); constraint and part existence; operational limits
   (`validation.operational`); duration, camera and references against the task
   contract; semantic review as approved.
5. **No unearned PASS:** code never writes `validation.judge = PASS` without the
   semantic review having run; `UNCERTAIN` goes to the human queue and is never
   turned into PASS. A compiled shot that has not passed every required check
   keeps a non-frozen status.
6. **Freeze writes a lock:** freezing writes a `LockRecord` with
   `lock_type = spec` and its own hash, checked through the `lock/spec` slot.
7. **Frozen is final:** after freeze, a change of prompt, source or parameter
   creates a new revision through the invalidation rules; a frozen spec is
   never edited.
8. **Raw results kept:** raw response, usage and lineage are stored even when
   the result is invalid; an invalid result is never applied.
9. **Rendering stays out:** Phases 7 and 8 render only frozen specs; wiring 6C
   into rendering is a separate, approved step.

## 4. Role of a model inside 6C **[DECIDED 2026-10-21]**

As the spec states, and no wider:

- **Action slot:** at most 25 words, one physical action; it must not mention
  shape, color or material (spec line 1820).
- **Semantic judge:** per P0/P1 constraint with status `must_show` or
  `may_be_hidden`, it answers consistent / inconsistent / unclear (Phase 6C
  Step 6).

Neither output has a schema among the eight v6.3 schemas. Adding one is a
separate decision that writes down only the rules above.
