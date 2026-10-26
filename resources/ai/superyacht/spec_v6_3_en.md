# superyacht\_concept\_film — v6.3 Final (EN)

v6 is the English runtime version: an automated pipeline that turns one superyacht article into a music-driven film about a completely new, never-before-seen superyacht of 110–180 m, from idea to design, construction, completion and life at sea.

## Overview

v6 has 21 phases in total: Phase 0 (initialisation) plus 20 production phases — v5 plus Phase 6C, the strict prompt compiler. The pipeline map below has exactly 21 rows. Every phase outputs JSON against a schema, is checked by three gate types (rules, judge, vision), and can run unattended with three optional human stops.

After v6.3 the next step is implementation and testing, not further document review.

### Pipeline map

| # | Phase | Needs | Runs in parallel with | Stop (REVIEW\_KEY mode) |
| --- | --- | --- | --- | --- |
| 0 | Pipeline init | — | — | — |
| 1 | Inspiration extraction | 0 | — | — |
| 2 | Design DNA + novelty brief | 1 | — | — |
| 3 | Yacht design + Canonical Design JSON + model sheet | 2 | — | **DESIGN LOCK** (lock record) |
| 3R | Reference pack | 3 | 3B, 4 | — |
| 3B | Visual story engine | 2, 3 | 3R | — |
| 4 | Visual script | 3, 3B | — | — |
| 4M | Music selection + timing lock | 3B, 4 | 5 | **TIMING LOCK** (automatic) |
| 5 | Locations + interior sets | 3, 4 | 4M | — |
| 5L | Look test + style lock | 3R, 5 | — | — |
| 6 | Shot list | 3B, 4, 4M, 5, 5L | — | — |
| 6C | Strict prompt compilation + validation | 3, 3R, 5, 5L, 6 | — | **SPEC FREEZE** (automatic; UNCERTAIN → human) |
| 7 | Keyframes + scoring | 6C | per location | — |
| 7A | Animatic | 4M, 6, 7 | — | **PACING LOCK** |
| 8 | Video shots + scoring | 6C, 7A | per shot | — |
| 8P | Clip post-processing | 8 | right after each passing clip | — |
| 9 | Assembly + mix | 4M, 8P | — | — |
| 9H | Opening lab | 9 | — | — |
| 9Q | Final QC | 9, 9H | — | **BEFORE PUBLISH** |
| 10 | Release, localization, analytics | 9Q | — | — |

### Three locks

- **Design lock (after Phase 3):** the Canonical Design JSON, constraint registry, model sheet and anchors are approved together. Changing any of them later regenerates the reference pack and every frame and clip containing the yacht.
- **Timing lock (after Phase 4M):** the film length, section boundaries and beat grid come from the chosen music. Shot durations snap to that grid.
- **Spec freeze (after Phase 6C):** every compiled shot spec is validated and frozen; Phases 7 and 8 may render only frozen specs.
- **Pacing lock (after Phase 7A):** shot order and durations approved on the animatic. Video budget is spent only after this lock.

Every lock is an immutable database record: lock type · artifact revision · content hash (e.g. SHA-256 of the canonical JSON) · approver (human or automatic) · timestamp. A change after a lock creates a new revision and triggers the invalidation rules below; the old revision is never edited.

### Invalidation rules

| Change | Must regenerate |
| --- | --- |
| YACHT ANCHOR or HEX colors | reference pack, model sheet, every yacht frame and clip, thumbnail |
| One build-state sentence | reference images, frames and clips in that state |
| One LOCATION or SET anchor | its reference image, frames and clips in it |
| Music track | timing lock, shot durations, animatic; frames are kept, clips re-trimmed (re-rendered only if a shot grows beyond its clip) |
| Shot order | animatic, EDL; no re-render |

## System prompt

```
You are a complete superyacht concept studio in one mind: design researcher, concept designer, naval-architecture sanity checker, story producer, production designer, director of photography, music supervisor, AI image and video prompt engineer, and film editor.

You read one article about a real superyacht ONLY as a reference for the state of the art. You never copy it. You invent a completely new superyacht of 110–180 metres whose silhouette, signature features and concept have never existed on any real vessel, while staying physically plausible.

You then produce every asset needed for a wordless, music-driven film that follows the yacht from first idea to design, construction, completion and life at sea — exterior, interior and every key room.

The film has no voiceover. Music from a licensed library carries the emotion; images carry the story; on-screen text is rare, short and factual about the concept.

Real-world facts from the article never appear in the film.
Every object carries a fixed ID.
Every image and video prompt is written in English.
Every output is JSON in the standard envelope.
```

### ID convention

| ID | Object |
| --- | --- |
| YACHT | the concept vessel |
| BS01–BS12 | build states |
| EXT01… | exterior zones (bow, aft decks, helideck, pool deck…) |
| INT01–INT12 | interior rooms |
| LOC01… | locations (studio, yard halls, sea areas) |
| SET01… | interior sets (a room at a given build state, e.g. INT03 bare vs finished) |
| A1–A4 / B0–B16 | acts and beats |
| SC01… / SC01\_SH01… | scenes and shots |
| MS1–MS8 | model sheet views |
| MUS01… | music tracks |
| SC01\_SH01\_START.png / \_END.png | keyframes |
| SC01\_SH01.mp4 | video clip |

### Global variables

| Variable | Meaning | Example |
| --- | --- | --- |
| {source\_article} | reference article | pasted text or URL |
| {studio\_name} | studio / channel credited for the concept | — |
| {length\_class} | target LOA band | 110–180 m (fixed range; Phase 3 picks a value) |
| {platform} | where it is published | YouTube / TikTok / Facebook Reels |
| {video\_length} | target length | 60 s / 120 s / 180 s |
| {aspect\_ratio} | primary aspect | 16:9 or 9:16 |
| {visual\_style} | starting style brief | photorealistic, cinematic, natural grade |
| {music\_library} | licensed music source | library API or local catalogue |
| {sfx\_library} | licensed sound-effects source | — |
| {image\_tools} | image models | gpt-image-2, Nano Banana Pro, Nano Banana 2 |
| {video\_tools} | video models | Veo 3.1 (Quality / Fast / Lite), Gemini Omni 1.1 Flash |
| {languages} | languages for text cards and descriptions | en, vi, … |
| {tone\_notes} | tone | understated, precise, peer-to-peer luxury |

## Common standards (apply to every phase)

### Output envelope

```json
{
  "phase": "4",
  "prompt_version": "6.3.0",
  "project_id": "20261020_noctiluca",
  "status": "ok",
  "data": { },
  "flags": [],
  "notes": ""
}
```

- `status`: `ok` · `partial` (fallback used) · `fail` (reason in `flags`).
- `notes` is human-readable only; no phase ever reads another phase’s `notes`.

### Schema rules

- Every key has a fixed type. Measurements are numbers with a separate unit: `{"value": 142, "unit": "m"}`.
- Every object has an `id`. Later phases reference by `id`; they never copy content.
- A missing required field fails the RULE gate immediately.
- Text that will be inserted into prompts (anchors, build-state sentences, camera sentences) lives in its own key and is inserted by code only, verbatim.

### Three gate types

| Gate | Runs as | Used for | Result |
| --- | --- | --- | --- |
| RULE | code | counts, regex, sums, ID checks, schema validation | pass / fail with location |
| JUDGE | a separate LLM call (never the model that wrote the output), temperature 0 | quality, plausibility, novelty, story clarity | 0–10 per rubric + reasons |
| VISION | vision model + measurement tools | text, faces, model-sheet similarity, flicker, hull stability | scores + pass / fail by threshold |

Order: RULE first; JUDGE and VISION only if RULE passes. A failure regenerates only the failing part with the exact error, at most 2 times; then the run mode in Phase 0 decides.

### Judge rubric format

The judge receives the output, the phase rules and the rubric, and returns JSON: score per criterion, total, the exact failing sentence or element, and a suggested fix. Default pass ≥ 8/10. Judge rules: score strictly, when in doubt deduct; every deduction quotes the offending item; never re-check what RULE gates already checked.

### Prompt versioning

- Each phase prompt has a version `major.minor.patch`: schema change → major; rule or format change → minor; wording or example change → patch.
- Every output records `prompt_version`.
- No new version goes into production before it passes the regression suite (Operations).

### Source of truth and precedence (v6)

When two artifacts disagree, the higher one wins and the lower one is regenerated:

1. Canonical Design JSON (Phase 3, locked)
2. Constraint registry (Phase 3, locked)
3. Approved model sheet (MS1 first)
5. Reference pack
6. Compiled shot specs (Phase 6C, frozen)
7. Generated frames and clips

No phase after the design lock may change items 1–2; it can only request a new design revision.

### Constraint registry

Every design rule that a frame or clip must respect is a constraint with an ID, a priority and a check method:

| Priority | Meaning | On violation |
| --- | --- | --- |
| P0 | identity: silhouette, bow type, deck count, signature innovations, hull color | reject, never published |
| P1 | proportion and placement: door and blister positions, glazing pattern, helideck position, room layout | reject unless the shot hides it (reveal level H1–H3) |
| P2 | detail and finish: rail style, small fittings, material grain | flag; accept if the shot is otherwise best |

Example entries: `C-P0-001 deck_count = 8` · `C-P0-002 bow = reverse-raked` · `C-P1-007 hull_doors_center_x = 0.52 × LOA` · `C-P2-014 rail = glass bulwark`.

### Verdict states

Every gate returns `PASS`, `FAIL` or `UNCERTAIN`. `UNCERTAIN` means a score within a calibrated margin of the threshold, judges disagreeing, or a check that cannot see the element. How an UNCERTAIN item is handled depends on its priority and the run mode (table below) — this table is the only rule for it in the whole document.

**UNCERTAIN policy by run mode (v6.1):**

| Priority of the uncertain item | REVIEW\_KEY / REVIEW\_ALL | FULL\_AUTO |
| --- | --- | --- |
| P0 | human queue; the shot waits | human queue; the shot waits (the rest of the pipeline continues) |
| P1 | human queue | try the next-best variant, then a coverage shot; still uncertain → human queue |
| P2 / creative quality | human queue | accept with flag `P2_UNCERTAIN` in the final report |

Only P0 items can stop a FULL\_AUTO run, and only for the affected shot and its dependants.

**Run end states (v6.2):**

| Run state | Meaning | Publishing |
| --- | --- | --- |
| `COMPLETED` | every artifact EXECUTED, no open review item | allowed after the final approval of the run mode |
| `COMPLETED_WITH_FLAGS` | only P2 items accepted with flag | allowed; flags listed in the QC report |
| `AWAITING_REVIEW` | at least one open P0 or P1 item affecting the final cut; everything else finished | blocked until the items are decided |
| `FAILED` | a phase failed after its retries or a BLOCKED item has no fallback | blocked |

FULL\_AUTO means “no planned stops”, not “no stops at all”: the only things that can hold a FULL\_AUTO run are P0 items (and P1 items after the fallback attempts fail).

### Determinism note

Temperature 0 and fixed seeds reduce but do not remove variation. Reproducibility comes from caching every result by input hash and keeping every rendered asset, not from expecting identical regenerations.

### Core JSON Schemas (v6.3)

These schemas are the implementation contract; every phase output is validated against them before any gate runs.

Eight schemas (v6.3). Shared definitions: `hash` = `^sha256:[a-f0-9]{64}$` · `id` = non-empty string · timestamps = ISO 8601 date-time · `status` = the artifact state machine enum (Technical architecture).

**1. CanonicalDesign**

```json
{
  "$id": "CanonicalDesign",
  "type": "object",
  "required": ["revision", "revision_kind", "hash", "name", "dimensions", "decks", "profile_curve", "plan_outline", "deck_outlines", "landmarks", "surfaces", "build_states", "operational_limits", "rooms"],
  "properties": {
    "revision": {"type": "integer", "minimum": 0},
    "revision_kind": {"enum": ["draft", "locked"]},
    "hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$"},
    "name": {"type": "string", "minLength": 2, "maxLength": 30},
    "dimensions": {"type": "object", "required": ["loa", "beam", "draft"], "properties": {"loa": {"$ref": "#/$defs/length_m"}, "beam": {"$ref": "#/$defs/length_m"}, "draft": {"$ref": "#/$defs/length_m"}}, "additionalProperties": {"$ref": "#/$defs/measure"}},
    "decks": {"type": "array", "minItems": 5, "items": {"type": "object", "required": ["name", "height_m", "start_x", "end_x"], "properties": {"name": {"type": "string"}, "height_m": {"type": "number", "exclusiveMinimum": 0}, "start_x": {"type": "number", "minimum": 0, "maximum": 1}, "end_x": {"type": "number", "minimum": 0, "maximum": 1}}}},
    "profile_curve": {"type": "array", "minItems": 20, "items": {"type": "array", "items": {"type": "number"}, "minItems": 2, "maxItems": 2}},
    "plan_outline": {"type": "array", "minItems": 20, "items": {"type": "array", "items": {"type": "number"}, "minItems": 2, "maxItems": 2}},
    "deck_outlines": {"type": "array", "items": {"type": "object"}},
    "landmarks": {"type": "object", "additionalProperties": {"type": "object", "required": ["x", "z"], "properties": {"x": {"type": "number", "minimum": 0, "maximum": 1}, "z": {"type": "number"}}}},
    "surfaces": {"type": "object", "required": ["finished", "colors"], "properties": {"colors": {"type": "array", "maxItems": 3, "items": {"type": "object", "required": ["role", "name", "hex"], "properties": {"hex": {"type": "string", "pattern": "^#[0-9A-Fa-f]{6}$"}}}}}},
    "build_states": {"type": "array", "minItems": 8, "maxItems": 8, "items": {"$ref": "BuildState"}, "description": "exactly BS05–BS12, part of the design lock"},
    "operational_limits": {"type": "array", "items": {"type": "object", "required": ["feature", "allowed_when"], "properties": {"feature": {"type": "string"}, "allowed_when": {"type": "object", "properties": {"max_speed_kn": {"type": "number", "minimum": 0}, "at_anchor_only": {"type": "boolean"}, "max_sea_state": {"type": "integer", "minimum": 0, "maximum": 9}}}}}},
    "rooms": {"type": "array", "minItems": 10, "items": {"type": "object", "required": ["id", "deck", "window_type"]}}
  },
  "allOf": [{"if": {"properties": {"revision_kind": {"const": "locked"}}}, "then": {"properties": {"revision": {"minimum": 1}}}}],
  "$defs": {
    "measure": {"type": "object", "required": ["value", "unit", "basis"], "properties": {"value": {"type": "number"}, "unit": {"type": "string"}, "basis": {"enum": ["CONCEPT_ASSUMPTION", "DERIVED", "TRACED"]}}},
    "length_m": {"type": "object", "required": ["value", "unit", "basis"], "properties": {"value": {"type": "number", "exclusiveMinimum": 0}, "unit": {"const": "m"}, "basis": {"enum": ["CONCEPT_ASSUMPTION", "DERIVED", "TRACED"]}}}
  }
}
```

**2. BuildState**

```json
{
  "$id": "BuildState",
  "type": "object",
  "required": ["id", "kind", "present_parts", "absent_parts", "surfaces_by_part", "compiled_text"],
  "properties": {
    "id": {"type": "string", "pattern": "^BS(0[5-9]|1[0-2])$"},
    "kind": {"enum": ["scale_model", "build", "finished"]},
    "present_parts": {"type": "array", "uniqueItems": true, "items": {"$ref": "#/$defs/part"}},
    "absent_parts": {"type": "array", "uniqueItems": true, "items": {"$ref": "#/$defs/part"}},
    "surfaces_by_part": {"type": "object", "propertyNames": {"$ref": "#/$defs/part"}, "additionalProperties": {"enum": ["scale_model_white", "bare_steel", "steel_primer", "raw_aluminium", "protective_film", "filler_primer", "final_paint", "final_glass", "teak", "other_final"]}},
    "compiled_text": {"type": "string", "description": "English STATE GEOMETRY + SURFACE STATE generated by code from the fields above"}
  },
  "$defs": {"part": {"enum": ["keel_blocks", "hull_shell", "main_deck", "superstructure", "glazing", "hull_doors", "observation_blister", "helideck", "mast", "stern_platform", "terraces", "scaffolding_on_hull"]}}
}
```

BuildState objects live inside CanonicalDesign (`build_states`), so they are locked and revisioned with it. BS01–BS04 are design states (drawings, screens) and need no BuildState.

**3. Constraint** (generated only from a canonical revision with `revision_kind = locked`, so `canonical_revision ≥ 1`)

```json
{
  "$id": "Constraint",
  "type": "object",
  "required": ["id", "priority", "statement", "check", "visible_from", "from_state", "canonical_revision"],
  "properties": {
    "id": {"type": "string", "pattern": "^C-P[0-2]-[0-9]{3}$"},
    "priority": {"enum": ["P0", "P1", "P2"]},
    "statement": {"type": "string", "maxLength": 200},
    "check": {"enum": ["count", "landmark", "vision_question", "color", "relation"]},
    "question": {"type": "string"},
    "part": {"type": "string"},
    "visible_from": {"type": "array", "items": {"type": "string"}, "minItems": 1},
    "from_state": {"type": "string", "pattern": "^BS(0[5-9]|1[0-2])$"},
    "until_state": {"type": ["string", "null"], "pattern": "^BS(0[5-9]|1[0-2])$"},
    "canonical_revision": {"type": "integer", "minimum": 1}
  }
}
```

A constraint applies from `from_state` to `until_state` (inclusive; null = to the end). Before `from_state` it is `not_yet_built`; after `until_state` (e.g. scaffolding removed) it is `not_applicable`. This replaces the single `exists_from_state` used in earlier text.

**4. ShotSpec** (one row of the Phase 6 shot list)

```json
{
  "$id": "ShotSpec",
  "type": "object",
  "required": ["shot_id", "beat", "act", "tl_in", "tl_out", "render_sec", "tool", "place", "state", "size", "angle", "lens_mm", "move", "action", "render_type", "difficulty", "reveal", "source", "operational_context", "coverage"],
  "properties": {
    "shot_id": {"type": "string", "pattern": "^SC[0-9]{2}_SH[0-9]{2}$"},
    "beat": {"type": "string", "pattern": "^B([0-9]|1[0-6])$"},
    "act": {"enum": ["A1", "A2", "A3", "A4"]},
    "tl_in": {"type": "number", "minimum": 0}, "tl_out": {"type": "number", "exclusiveMinimum": 0},
    "render_sec": {"type": "number", "minimum": 2, "maximum": 40},
    "tool": {"type": "string"},
    "place": {"type": "string", "pattern": "^(LOC[0-9]{2}|SET_INT[0-9]{2}_(bare|fitout|finished))$"},
    "state": {"type": "string", "pattern": "^BS(0[1-9]|1[0-2])$"},
    "size": {"enum": ["EWS", "WS", "MS", "CU", "ECU", "AERIAL"]},
    "angle": {"type": "string"}, "lens_mm": {"type": "integer", "minimum": 8, "maximum": 600},
    "move": {"type": "object", "required": ["type", "direction", "speed"], "properties": {"type": {"type": "string"}, "direction": {"type": "string"}, "speed": {"enum": ["very_slow", "slow", "medium"]}}},
    "action": {"type": "object", "required": ["subject", "verb"], "properties": {"subject": {"type": "string"}, "verb": {"type": "string"}, "object": {"type": "string"}, "detail": {"type": "string", "maxLength": 160}}},
    "card": {"type": ["string", "null"]},
    "render_type": {"enum": ["A", "B", "C", "D"]},
    "difficulty": {"enum": ["EASY", "MEDIUM", "HARD"]},
    "fallback": {"type": "string"},
    "reveal": {"enum": ["H1", "H2", "H3", "H4", "H5"]},
    "source": {"enum": ["ai", "live", "stock"]},
    "operational_context": {"type": "object", "required": ["speed_kn", "at_anchor"], "properties": {"speed_kn": {"type": "number", "minimum": 0, "maximum": 35}, "at_anchor": {"type": "boolean"}, "features_active": {"type": "array", "uniqueItems": true, "items": {"type": "string"}}}},
    "sequence_id": {"type": "string"}, "match_pair": {"type": ["string", "null"]},
    "characters": {"type": "array", "uniqueItems": true, "items": {"enum": ["HANDS", "CREW", "CAPTAIN"]}},
    "coverage": {"type": "boolean"}
  },
  "allOf": [{"if": {"properties": {"operational_context": {"properties": {"at_anchor": {"const": true}}}}}, "then": {"properties": {"operational_context": {"properties": {"speed_kn": {"const": 0}}}}}}]
}
```

**5. CompiledShot**

```json
{
  "$id": "CompiledShot",
  "type": "object",
  "required": ["shot_id", "revision", "hash", "state", "sources", "image_tool", "image_mode", "video_tool", "video_mode", "image_prompt", "motion_prompt", "refs", "constraints_to_check", "validation", "status"],
  "properties": {
    "shot_id": {"type": "string", "pattern": "^SC[0-9]{2}_SH[0-9]{2}$"},
    "revision": {"type": "integer", "minimum": 1},
    "hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$"},
    "state": {"type": "string", "pattern": "^BS(0[1-9]|1[0-2])$"},
    "sources": {"type": "object", "additionalProperties": {"type": "string", "pattern": "^rev[0-9]+$"}},
    "image_tool": {"type": "string"},
    "image_mode": {"enum": ["generate", "edit_from_reference", "none"]},
    "video_tool": {"type": "string"},
    "video_mode": {"enum": ["first_frame", "first_last_frame", "reference", "video_reference", "extend", "edit", "none"]},
    "image_prompt": {"type": "array", "minItems": 1, "items": {"type": "object", "required": ["block", "text", "source"], "properties": {"block": {"enum": ["reference_instruction", "identity", "state", "location", "action", "character", "camera", "light", "style", "aspect", "constraints"]}, "text": {"type": "string", "minLength": 1}, "source": {"type": "string", "pattern": "^[a-z_.A-Z0-9]+@[0-9]+$"}}}},
    "motion_prompt": {"type": "string", "description": "empty string only when video_mode = none"},
    "refs": {"type": "array", "uniqueItems": true, "items": {"type": "string"}},
    "constraints_to_check": {"type": "array", "items": {"type": "object", "required": ["id", "status"], "properties": {"id": {"type": "string", "pattern": "^C-P[0-2]-[0-9]{3}$"}, "status": {"enum": ["must_show", "may_be_hidden", "not_applicable", "not_yet_built"]}}}},
    "validation": {"type": "object", "required": ["rule", "judge", "operational"], "properties": {"rule": {"enum": ["PASS", "FAIL"]}, "judge": {"enum": ["PASS", "FAIL", "UNCERTAIN"]}, "operational": {"enum": ["PASS", "BLOCKED"]}}},
    "status": {"enum": ["DRAFT", "VALIDATING", "REVIEW_REQUIRED", "APPROVED", "FROZEN", "EXECUTED", "FAILED", "BLOCKED", "STALE"]}
  },
  "allOf": [
    {"if": {"properties": {"status": {"enum": ["APPROVED", "FROZEN", "EXECUTED"]}}}, "then": {"properties": {"validation": {"properties": {"rule": {"const": "PASS"}, "judge": {"const": "PASS"}, "operational": {"const": "PASS"}}}}}},
    {"if": {"properties": {"video_mode": {"not": {"const": "none"}}}}, "then": {"properties": {"motion_prompt": {"minLength": 1}}}}
  ]
}
```

A human approval of an UNCERTAIN judge result is recorded as a ReviewDecision and sets `validation.judge` to `PASS` with the decision id in the artifact log.

**6. LockRecord** (a design lock is a bundle: one record lists every component with its own hash, plus a bundle hash)

```json
{
  "$id": "LockRecord",
  "type": "object",
  "required": ["id", "project_id", "lock_type", "components", "bundle_hash", "approver", "approved_at"],
  "properties": {
    "id": {"type": "string", "format": "uuid"},
    "project_id": {"type": "string", "minLength": 1},
    "lock_type": {"enum": ["design", "timing", "spec", "pacing", "publish"]},
    "components": {"type": "array", "minItems": 1, "items": {"type": "object", "required": ["artifact_id", "artifact_type", "revision", "hash"], "properties": {"artifact_id": {"type": "string"}, "artifact_type": {"type": "string"}, "revision": {"type": "integer", "minimum": 0}, "hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$"}}}},
    "bundle_hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$", "description": "SHA-256 of the sorted component hashes"},
    "approver": {"type": "string", "description": "user id or 'system'"},
    "approved_at": {"type": "string", "format": "date-time"}
  }
}
```

**7. ReviewDecision**

```json
{
  "$id": "ReviewDecision",
  "type": "object",
  "required": ["id", "item_id", "item_type", "reason", "priority", "decision", "decided_by", "decided_at"],
  "properties": {
    "id": {"type": "string", "format": "uuid"},
    "item_id": {"type": "string", "minLength": 1},
    "item_type": {"enum": ["canonical", "model_sheet", "compiled_shot", "frame", "clip", "animatic", "export"]},
    "reason": {"enum": ["UNCERTAIN", "BLOCKED", "ANIMATIC_LOW", "DESIGN_LOCK", "PUBLISH"]},
    "priority": {"enum": ["P0", "P1", "P2", "none"]},
    "decision": {"enum": ["approve", "reject", "rerender", "swap_variant", "swap_coverage", "revise_design"]},
    "decided_by": {"type": "string", "minLength": 1},
    "decided_at": {"type": "string", "format": "date-time"},
    "note": {"type": "string", "maxLength": 1000}
  }
}
```

**8. RenderRequest** (the immutable snapshot of exactly what is sent to a provider API; created and frozen right before each call)

```json
{
  "$id": "RenderRequest",
  "type": "object",
  "required": ["id", "compiled_shot_id", "compiled_shot_revision", "compiled_shot_hash", "kind", "provider", "model_id", "endpoint", "adapter_version", "parameters", "inputs", "prompt_text", "request_hash", "created_at", "status"],
  "properties": {
    "id": {"type": "string", "format": "uuid"},
    "compiled_shot_id": {"type": "string", "pattern": "^SC[0-9]{2}_SH[0-9]{2}$"},
    "compiled_shot_revision": {"type": "integer", "minimum": 1},
    "compiled_shot_hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$"},
    "kind": {"enum": ["image", "video", "video_extend", "video_edit", "upscale"]},
    "provider": {"enum": ["openai", "google_gemini_api", "google_vertex_ai"]},
    "model_id": {"type": "string", "minLength": 1},
    "model_snapshot": {"type": ["string", "null"]},
    "endpoint": {"type": "string", "minLength": 1},
    "adapter_version": {"type": "string", "pattern": "^[0-9]+\\.[0-9]+\\.[0-9]+$"},
    "parameters": {"type": "object", "required": ["aspect_ratio", "resolution", "seed_policy"], "properties": {
      "aspect_ratio": {"enum": ["16:9", "9:16", "1:1", "4:3", "3:2"]},
      "resolution": {"type": "string", "pattern": "^([0-9]{3,4}x[0-9]{3,4}|360p|720p|1080p|4K|1K|2K)$"},
      "duration_sec": {"type": "number", "minimum": 1, "maximum": 40},
      "fps": {"type": "integer", "minimum": 12, "maximum": 60},
      "seed": {"type": ["integer", "null"]},
      "seed_policy": {"enum": ["fixed", "hash_of_shot", "not_supported"]},
      "variants": {"type": "integer", "minimum": 1, "maximum": 8},
      "audio": {"enum": ["ambient_only", "off"]},
      "extra": {"type": "object", "description": "every other provider parameter, written explicitly — no reliance on provider defaults"}
    }},
    "inputs": {"type": "array", "items": {"type": "object", "required": ["role", "path", "hash"], "properties": {"role": {"enum": ["edit_input", "reference", "first_frame", "last_frame", "video_reference", "source_video"]}, "path": {"type": "string"}, "hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$"}}}},
    "prompt_text": {"type": "string", "minLength": 1},
    "request_hash": {"type": "string", "pattern": "^sha256:[a-f0-9]{64}$", "description": "SHA-256 of the canonical serialisation of the full request body"},
    "created_at": {"type": "string", "format": "date-time"},
    "response_id": {"type": ["string", "null"]},
    "status": {"enum": ["FROZEN", "EXECUTED", "FAILED"]}
  }
}
```

Rules: every parameter the provider accepts is written explicitly (no defaults); a retry re-sends the same RenderRequest; any change (adapter version, model snapshot, parameter, input file) creates a new RenderRequest. The job cache key is `request_hash`.

### Cross-field invariants (v6.3)

Rules that JSON Schema cannot express are enforced by the Laravel business validator. Each invariant has an ID, at least one valid and one invalid fixture, and a unit test.

| ID | Object | Invariant |
| --- | --- | --- |
| INV-CD-01 | CanonicalDesign | every deck has `start_x < end_x` |
| INV-CD-02 | CanonicalDesign | `build_states` contains BS05–BS12 exactly once each, in order |
| INV-CD-03 | CanonicalDesign | `operational_limits[].feature` refers to a known feature of the design |
| INV-BS-01 | BuildState | `present_parts ∩ absent_parts = ∅` |
| INV-BS-02 | BuildState | keys of `surfaces_by_part` ⊆ `present_parts` |
| INV-BS-03 | BuildState | parts present in state N are present or deliberately removed (e.g. scaffolding) in state N+1 |
| INV-BS-04 | BuildState | `compiled_text` equals the compiler output for the current fields (re-compiled and compared) |
| INV-CN-01 | Constraint | `from_state ≤ until_state` when `until_state` is set |
| INV-CN-02 | Constraint | its `part` is in `present_parts` of every state from `from_state` to `until_state` |
| INV-CN-03 | Constraint | `canonical_revision` equals the locked canonical revision |
| INV-SS-01 | ShotSpec | `tl_out > tl_in` and `tl_out − tl_in ≤ render_sec` |
| INV-SS-02 | ShotSpec | `tl_in`, `tl_out` lie on the locked cut grid |
| INV-SS-03 | ShotSpec | `reveal = H5` only on or after the hero beat; no `finished` set before A4 |
| INV-SS-04 | ShotSpec | `operational_context` satisfies the canonical `operational_limits` |
| INV-CS-01 | CompiledShot | `state` equals the ShotSpec state; every `sources` revision exists and is locked |
| INV-CS-02 | CompiledShot | each constraint status matches its `from_state` / `until_state` and the shot state |
| INV-CS-03 | CompiledShot | parts mentioned positively in identity / state blocks ⊆ `present_parts` of the state |
| INV-RR-01 | RenderRequest | `compiled_shot_hash` equals the hash of a FROZEN CompiledShot |
| INV-RR-02 | RenderRequest | every input `hash` matches the stored file; `model_id` is in the verified tool profile |
| INV-RR-03 | RenderRequest | `duration_sec` is an allowed clip length for the model; `aspect_ratio` and `resolution` are supported |
| INV-LK-01 | LockRecord | `bundle_hash` = SHA-256 of the sorted component hashes; every component revision exists |
| INV-RD-01 | ReviewDecision | `decided_by` is a human user for reasons DESIGN\_LOCK and PUBLISH; the decision is allowed for the `item_type` |

**Fixtures and tests:** each schema ships with at least one valid and three invalid fixtures in the code repository. A CI test validates every fixture and every JSON example marked **complete** in this document. Examples marked **partial** in the phases show only the relevant fields of `data` and are not used as fixtures; the Phase 6C example is complete.

## Phase 0 — Pipeline init

Phase 0 runs before everything else and writes the PROJECT MANIFEST that every later phase reads and writes. It decides whether to run, what each tool can do, how much may be spent, how outputs are checked and where to stop.

You are the pipeline orchestrator of an automated AI video production system.

### Absolute rules

- No phase runs while the manifest still contains an unresolved `{variable}`.
- Anchors, build-state sentences and camera sentences are referenced by KEY; code inserts them verbatim. No LLM ever retypes them.
- Every machine-measurable check runs as code, not as LLM self-assessment.
- Budgets are hard caps. When a cap is reached, fall back; never call again.
- Every asset has a status and a log. The pipeline resumes from the failed step without regenerating passed assets.

### Step 1 — Source check (go / no-go)

The article is a reference, so it is scored on design richness, not on specs.

| Criterion | Points |
| --- | --- |
| About a specific superyacht or a superyacht design | required (else STOP) |
| ≥ 3 exterior design features described | 3 |
| ≥ 2 interior spaces or materials described | 2 |
| A design intent, brief or concept stated | 2 |
| Technology or propulsion described | 1 |
| Size, decks or layout described | 1 |
| Images or captions describing the look | 1 |

< 5 → REJECT with reason. 5–6 → RUN with flag `THIN_SOURCE` (Phase 2 creates more original seeds). ≥ 7 → RUN.

### Step 2 — Tool profiles

Capabilities below are as reported in October 2026 (see Sources at the end); verify against the live API at setup and store the result in the manifest.

| Tool | Model ID (API) | Key capabilities used here |
| --- | --- | --- |
| GPT Image 2 | `gpt-image-2` | text + image input, editing, strong instruction following, up to about 2K output, several aspect ratios incl. 16:9 |
| Nano Banana Pro | `gemini-3-pro-image-preview` | up to 14 reference images per prompt, 1K / 2K / 4K output, editing |
| Nano Banana 2 | `gemini-3.1-flash-image-preview` | faster and cheaper; multi-reference; good for drafts and coverage |
| Veo 3.1 (Quality / Fast / Lite) | `veo-3.1-generate-preview` and variants | 4, 6 or 8 s clips; first-and-last-frame mode; reference mode with 1–3 images; extend; native audio; up to 4K on some endpoints |
| Gemini Omni 1.1 Flash | `gemini-omni-1.1-flash` (preview) | 3–10 s clips; first-and-last frame; image references and video references up to 3 s; edit by instruction; extend in 3–10 s hops up to 40 s; 360p drafts upscaled to 1080p / 4K |

Profile fields stored per tool: `max_clip_sec`, `clip_lengths[]`, `first_last_frame`, `max_ref_images`, `video_ref`, `edit`, `extend_max_sec`, `native_audio`, `max_resolution`, `aspect_ratios[]`, `prompt_max_chars`, `cost_unit`, `typical_latency`.

Provider caveats to verify: on some endpoints Veo 3.1 reference mode is fixed to 8 s and cannot be combined with first-and-last-frame mode in one request; Omni 1.1 Flash is in preview and can take several minutes per clip.

**Model IDs verified against official documentation (v6.1, checked October 2026):**

| Tool | Official ID | Verified facts | Still to confirm by live call |
| --- | --- | --- | --- |
| GPT Image 2 | `gpt-image-2` (snapshot `gpt-image-2-2026-04-21`) | text + image input, image output; endpoints `v1/images/generations` and `v1/images/edits` | max input images per edit, sizes |
| Nano Banana Pro | `gemini-3-pro-image-preview` | listed in the Gemini API image-generation docs | a stable name `gemini-3-pro-image` is reported by third parties; reference-image limit |
| Nano Banana 2 | `gemini-3.1-flash-image-preview` | reported by third parties only | ID, limits |
| Veo 3.1 | Gemini API: `veo-3.1-generate-preview`, `veo-3.1-fast-generate-preview` · Vertex AI: `veo-3.1-generate-001`, `veo-3.1-fast-generate-001` | official video docs; long-running operation | clip lengths, reference / frame modes per endpoint |
| Gemini Omni Flash | `gemini-omni-1.1-flash` (stable) · preview `gemini-omni-flash-preview` | official model page: text, image and video input (video up to 10 s for editing and extension); output 3–10 s at 24 fps, 360p / 720p / 1080p / 4K; called through the Interactions API | aspect ratios, reference limits, first-and-last-frame behaviour |

Google’s own guidance recommends Omni Flash as the default video model and Veo 3.1 where scene extension or last-frame control is needed. v6.1 routing follows this: build-state morphs that need an exact END frame use Veo 3.1; continuity, interiors and edits use Omni Flash. A live capability test at setup is still required; a mismatch later blocks the phase and alerts.

### Step 3 — Routing defaults

| Task | Primary | Secondary | Why |
| --- | --- | --- | --- |
| Design sketches, concept boards, GA-style plans without text | GPT Image 2 | Nano Banana Pro | instruction following, clean line work |
| Model sheet (MS1–MS8) | both, scored | — | pick the more consistent set |
| Shots with the yacht | Nano Banana Pro (up to 14 approved reference images) | GPT Image 2 (edit on an approved reference image) | multi-reference consistency |
| Interior sets | Nano Banana Pro | GPT Image 2 | material fidelity with set references |
| Coverage, drafts, look tests | Nano Banana 2 | — | cost |
| Exterior hero, sea trials, aerials, water | Veo 3.1 Quality | Omni 1.1 Flash | water physics, realism |
| Build-state morphs (BS → BS) | Veo 3.1 first-and-last frame | Omni first-and-last frame | controlled transition |
| Interior walkthroughs, long continuous moves | Omni 1.1 Flash (refs + extend) | Veo 3.1 extend | continuity over > 8 s |
| Continuity across consecutive shots | Omni 1.1 Flash with a 3 s video reference from the previous clip | — | visual continuity |
| Motion drafts for the animatic | Omni 1.1 Flash 360p | — | cheap preview |
| Fixing a defect in an otherwise good clip | Omni 1.1 Flash edit | re-render | cheaper than re-render |

**Native audio:** video models may generate sound. Every motion prompt asks for “ambient sound only, no music, no speech”. Native ambient sound may be kept as a low SFX bed in Phase 9; it is never the main audio.

### Step 4 — Derived values

- `target_shots` ≈ {video\_length} ÷ 3.2 s (music-driven films cut slightly faster).
- Act split (Phase 3B / 4) and dosage by length.
- Export specs from {platform}.
- Scan the manifest: any remaining `{…}` → STOP.

### Step 5 — Budget caps

| Item | Cap formula | Example, 120 s |
| --- | --- | --- |
| Model sheet + reference pack + location/set refs | (8 + refs + LOC + SET) × 4 variants × 2 rounds | about 320 images |
| Keyframes | frames × variants (3–4) × 1.5 rounds | about 280 images |
| Video | shots × 3 attempts (+ drafts at 360p) | about 120 clips + drafts |
| LLM regenerations | phases × 2 | 40 |

Max 3 video attempts per shot, then the fallback ladder. Cost cap reached → stop new renders, switch remaining shots to fallback level 3 (still + motion in edit).

These caps are **provisional**. After the shot list is locked (Phase 6), the final caps are recomputed from the real plan: number of keyframes per type, variants, clip lengths per tool, extensions, coverage shots and retries. The final caps replace the provisional ones in the manifest.

### Step 6 — Data contract

| Phase | Writes keys |
| --- | --- |
| 1 | `inspiration` |
| 2 | `dna`, `novelty` |
| 3 | `yacht_bible`, `anchors.yacht`, `anchors.exterior{}`, `anchors.interior{}`, `build_states{}`, `model_sheet[]` |
| 3R | `ref_pack` |
| 3B | `story`, `anchors.character{}`, `reveal_plan`, `loops[]`, `obstacles[]` |
| 4 | `script.beats[]`, `script.text_cards[]`, `script.music_brief` |
| 4M | `music`, `timing` |
| 5 | `locations[]`, `sets[]`, `anchors.location{}`, `anchors.set{}` |
| 5L | `style`, `motion_test` |
| 6 | `shots[]`, `progress_angle` |
| 7 | `shots[].frames` |
| 7A | `animatic` |
| 8 | `shots[].clip` |
| 8P | `shots[].post` |
| 9 | `timeline`, `mix`, `exports[]`, `post_assets` |
| 9H | `openings[]` |
| 9Q | `qc` |
| 10 | `releases[]`, `localizations[]`, `published[]`, `analytics`, `features` |

Image prompts are stored as recipes, e.g. `["anchors.yacht", "build_states.BS07", "anchors.location.LOC04", "action", "camera", "light", "style", "aspect"]`.

Additional keys in v6: `canonical` and `constraints[]` (Phase 3) · `compiled_shots[]` (Phase 6C) · `locks[]` (every lock record) · `review_queue[]` (all UNCERTAIN items).

### Step 7 — Run modes

| Mode | Human stops | Use when |
| --- | --- | --- |
| FULL\_AUTO | no planned stops; open P0 items (and P1 after failed fallbacks) wait for a human — see the UNCERTAIN policy and run end states | stable production |
| REVIEW\_KEY (default) | after model sheet · after animatic · before publish | normal use |
| REVIEW\_ALL | after every phase | pilots, prompt tuning |

### Step 8 — State, files, resume

- `project_id` = date + concept name (after Phase 3).
- File names follow the ID convention; retries add `_r1`, `_r2`.
- Asset status follows the artifact state machine (Technical architecture): `DRAFT`, `VALIDATING`, `REVIEW_REQUIRED`, `APPROVED`, `FROZEN`, `EXECUTED`, `FAILED`, `BLOCKED`, `STALE`. A fallback is a new artifact with a `fallback_level`, not a separate status.
- Every call logs: tool · model ID · assembled prompt · seed · attached references · duration · cost · gate scores.
- Resume skips artifacts in `EXECUTED` (and `FROZEN` ones whose inputs are unchanged), re-queues `FAILED` and `STALE` artifacts and their dependants (Overview: invalidation rules), and leaves `BLOCKED` and `REVIEW_REQUIRED` items in the review queue.

### Step 9 — Channel bible

A shared config loaded into every project:

| Item | Content |
| --- | --- |
| Type system | fonts, sizes, colors, in/out motion for text cards and the CONCEPT label |
| Channel mark | small logo + 1–2 s sonic logo |
| Music signature | preferred genres, BPM range, instrumentation palette across videos |
| End card | fixed template + standard disclaimer, pre-translated for {languages} |
| Graphic palette | 3–4 colors for text and graphics |
| Title frame | preferred title structure |

The first 3 s always belong to the hook; the channel mark appears after it (about 3–5 s) or small in a corner.

### Step 10 — Compliance

- Platform AI-content label on; disclaimer text ready: “Concept design by {studio\_name}. Not a real vessel.”
- Blocklist of real shipyards, designers, brands and famous yacht names for RULE gates.
- Music, SFX and fonts must have license evidence before publish.

### Output

```json
{
  "project_id": "20261020_pending",
  "mode": "REVIEW_KEY",
  "source": {"url": "…", "score": 8, "verdict": "RUN", "flags": []},
  "variables": {"video_length": 120, "aspect_ratio": "16:9", "length_class": "110-180m"},
  "derived": {"target_shots": 38},
  "tools": {"image": ["gpt-image-2", "gemini-3-pro-image-preview", "gemini-3.1-flash-image-preview"], "video": ["veo-3.1", "gemini-omni-1.1-flash"]},
  "budget": {"image_calls": 600, "video_calls": 120, "llm_retries": 40},
  "gates": {"judge_min": 8, "max_regen": 2}
}
```

### Quality gate

- [ ] Source scored with RUN / REJECT and reason
- [ ] Every tool profiled from the live API; missing features have a substitute
- [ ] No unresolved `{variable}`
- [ ] Budget caps set for images, video, LLM
- [ ] Every phase has read/write keys
- [ ] Run mode chosen; channel bible loaded; AI label and disclaimer ready

## Phase 1 — Inspiration extraction

Phase 1 reads the article as a design researcher. It extracts what the article reveals about current superyacht design — so the new design can go beyond it — and records the source yacht’s identity so the new design can avoid it. Nothing extracted here is ever shown in the film.

You are a superyacht design researcher. **Input:** {source\_article}.

### Absolute rules

- Extract only what the article states. Missing → `null`. Never fill from general knowledge.
- Preserve exact wording in `source_quote` for every item.
- Separate “what exists” (state of the art) from “what the source yacht is” (identity to avoid).
- No editorializing, no evaluation of quality.

### Extract

1. **Source identity (to avoid):** yacht name, builder, designers, overall silhouette description, bow type, superstructure profile, color scheme, signature features as described.
2. **Exterior design vocabulary:** every exterior feature described (decks, pools, beach club, helideck, glazing, terraces, tender garages, masts, hull openings).
3. **Interior vocabulary:** spaces, layouts, materials, finishes, art, lighting, atmosphere.
4. **Technology:** propulsion, hybrid / battery, hull form, stabilization, sustainability, smart systems.
5. **Scale signals:** length, decks, guest and crew counts, volume (only as stated).
6. **Design intent:** brief, inspiration, owner wishes, design philosophy.
7. **Trends implied:** what the article presents as new or leading-edge.

### Rules with examples

- Keep description, not judgment.
  - Wrong: “a stunning, iconic profile”
  - Right: “plumb bow, five-deck superstructure with continuous glazing” (with `source_quote`)
- Never merge two features into one item.
- Names exactly as written; never expand or substitute.

### Output

```json
{
  "inspiration": {
    "source_identity": {"name": "…", "silhouette": "…", "bow": "…", "colors": "…", "signatures": ["…"]},
    "exterior": [{"id": "X01", "feature": "…", "source_quote": "…"}],
    "interior": [{"id": "I01", "feature": "…", "source_quote": "…"}],
    "technology": [{"id": "T01", "feature": "…", "source_quote": "…"}],
    "scale": {"loa": null, "decks": null},
    "intent": "…",
    "trends": ["…"]
  }
}
```

### Gates

- **RULE:** every `source_quote` appears verbatim in the article; no duplicate features; `source_identity` present.
- **JUDGE:** important design features missed? (≥ 8/10)
- **Model setting:** temperature 0.

### Quality gate

- [ ] Source identity recorded for avoidance
- [ ] Exterior, interior, technology lists each traceable by quote
- [ ] No evaluation words; nothing invented

## Phase 2 — Design DNA and novelty brief

Phase 2 turns the reference into a brief for something that does not exist yet. The article sets the bar; the brief must clear it with a new concept, a new silhouette and at least two ideas with no known precedent that are still physically plausible.

You are a creative director for superyacht concepts. **Input:** `inspiration` (Phase 1).

### Absolute rules

- The article is a floor, not a template. Nothing in the brief may restate a source feature with cosmetic changes.
- The concept theme comes from OUTSIDE yachting (architecture, nature, aerospace, geology, sculpture, engineering).
- At least 2 signature innovations must be distinctive originals: no known yacht or published concept shows them in this form, as far as the novelty checks in Step 7 can find. If unsure whether something exists, treat it as existing and invent further. Never describe any innovation as “world-first” or “never seen before” in published text; the honest claim is “original concept design”.
- Every innovation must be buildable with known materials and engineering at 110–180 m; no fantasy (no floating parts, no impossible cantilevers, no transparent hulls below the waterline at speed).
- Avoid superyacht clichés (see must-avoid list).

### Step 1 — Concept theme

One line, visual, borrowed from outside yachting.

- Wrong: “luxury redefined for a new generation”
- Wrong: “a sleeker version of the source yacht”
- Right: “A hull shaped like a wind-eroded sandstone arch: one continuous curve from bow to stern, with the superstructure carved out of it rather than stacked on top.”

### Step 2 — Departure points

List 3–5 departure points. Each cites a Phase 1 item it departs FROM (`from_id`), names the departure method, and states the result.

| Method | What it does |
| --- | --- |
| CROSS-DOMAIN | transfers a principle from another field (cantilevered architecture, aircraft wing, seashell spiral) |
| INVERT | reverses a convention (deck spaces that open downward to the sea instead of upward) |
| MERGE | fuses two separate functions into one new element |
| RELOCATE | moves a function somewhere no yacht puts it |
| SCALE SHIFT | exploits 110–180 m to do what smaller yachts cannot |
| SUBTRACT | removes an expected element and builds the identity around its absence |

- Wrong: “Source has a beach club → bigger beach club.” (cosmetic)
- Right: “From X04 (aft beach club) · RELOCATE + SCALE SHIFT · a 30 m ‘sea hall’ inside the hull at midships, opened by twin hull doors that fold down into floating terraces on both sides.”

### Step 3 — Signature innovations (exactly 3)

Each: name · what it is · why it is new · how it is plausible (structure, materials, mechanism) · visible from where (exterior / interior / both).

### Step 4 — Silhouette definition

One paragraph that defines the side profile as a shape a child could draw in one line. It must be recognizable as a pure black silhouette and not match any well-known superyacht profile type (tiered wedding-cake, generic wave-piercer, classic explorer).

### Step 5 — Must-avoid list (≥ 8 items)

- Every identity marker of the source yacht (from `source_identity`).
- Common clichés: stacked identical decks with uniform glazing bands, generic swoosh lines along the hull, chrome-and-gold excess, a helipad as the only feature on the foredeck, etc.

### Step 6 — Hero moment and emotion

- **Hero moment:** one image the whole film builds toward: subject + action + place + light.
  - Right: “YACHT at anchor inside a basalt sea-gate at blue hour, sea hall doors folded open as lit floating terraces, drone rising slowly from 20 to 60 m. (The doors may open only at anchor — see operational limits in Phase 3.)”
- **Dominant emotion:** awe · serenity · power · mystery · freedom · precision.

### Step 7 — Novelty verification (v6)

An LLM cannot prove that nothing similar exists, so novelty is checked from three sides and the result is recorded, not assumed:

1. **Text search:** web search for each innovation in plain words (e.g. “superyacht hull doors fold down floating terraces”); list the closest hits with links.
2. **Image comparison (after the model sheet exists, Phase 3):** reverse image search of MS1 and MS2, plus embedding similarity against a maintained comparison set of well-known superyachts and published concepts; store the top 5 nearest with scores.
3. **Judge:** the novelty judge receives the hits from 1–2 and scores how close the nearest one is.

Verdict: `ORIGINAL` (no close match), `UNCERTAIN` (a partial match — human decides), `TOO_CLOSE` (redesign the affected innovation). The search results are stored in `novelty.evidence[]`.

### Output

```json
{
  "dna": {
    "theme": "…",
    "departures": [{"id": "D1", "from_id": "X04", "method": "RELOCATE", "result": "…"}],
    "innovations": [{"id": "N1", "name": "…", "what": "…", "why_new": "…", "plausibility": "…", "visible": "both"}],
    "silhouette": "…",
    "must_avoid": ["…"],
    "hero_moment": "…",
    "emotion": "awe"
  },
  "novelty": {"judge_score": null, "closest_known": null}
}
```

### Gates

- **RULE:** 3–5 departures (5 if `THIN_SOURCE`), each with a valid `from_id` and allowed method; exactly 3 innovations; must-avoid ≥ 8; no blocklisted names.
- **JUDGE — novelty (≥ 8/10):** “Name the closest existing yacht or concept to this design. Score 10 if nothing comparable exists, 0 if a known yacht already has this silhouette or these innovations.” Store `closest_known`.
- **JUDGE — plausibility (≥ 8/10):** naval architecture and structure sanity for each innovation.
- **Model setting:** temperature 0.8–0.9 for generation; the judges run at 0.

### Quality gate

- [ ] Theme from outside yachting, visual, no adjectives of quality
- [ ] 3 innovations, at least 2 with no close match in the novelty checks, all plausible
- [ ] Silhouette defined as a one-line shape
- [ ] Must-avoid list includes source identity and clichés
- [ ] Novelty ≥ 8 and plausibility ≥ 8

## Phase 3 — Yacht design (Yacht Bible)

Phase 3 turns the novelty brief into one fully specified, original superyacht between 110 and 180 m: identity, plausible specs, exterior, every key room, build states and the anchors that keep it identical in every frame.

You are a senior superyacht concept designer working for {studio\_name}. **Input:** `dna`, `novelty` (Phase 2) · `inspiration.source_identity` (to avoid).

### Absolute rules

- The yacht is FICTIONAL. Never write as if it exists, was ordered, built or sold.
- Credit only {studio\_name}. No real shipyard, designer, naval architect, brand, engine maker, tender maker, furniture or art brand.
- LOA between 110 and 180 m. Every spec plausible for the chosen LOA (table below) or justified in one line.
- Every must-avoid item satisfied; every innovation from Phase 2 present and visible.
- Describe with geometry, proportion and material — never with quality adjectives.

**Forbidden words** in all design text and anchors: luxurious, stunning, elegant, sleek, breathtaking, iconic, timeless, sophisticated, world-class, state-of-the-art, cutting-edge, futuristic, one of a kind, unparalleled, beautiful, opulent, majestic.

- Wrong: “A sleek, futuristic giant with stunning lines.”
- Right: “A single sandstone-colored hull curve rising 22 m at the stern, superstructure recessed into it in four terraces, no visible mast.”

### Step 1 — Name

1–2 words, easy to pronounce in English and in {languages}, linked to the theme; must not match or echo the source yacht or any famous yacht; no brand words, numbers or “Project X”.

- Wrong: “Azure Majesty II”. Right: “Noctiluca”.

### Step 2 — Specs (plausibility)

Approximate reference ranges for motor superyachts (guide, not law):

| LOA | Beam | Gross tonnage | Decks | Guests | Crew |
| --- | --- | --- | --- | --- | --- |
| 110–130 m | 16–19 m | 3,000–6,000 | 6–7 | 12–30 | 35–60 |
| 130–155 m | 18–22 m | 5,000–10,000 | 7–8 | 12–36 | 50–80 |
| 155–180 m | 20–25 m | 8,000–16,000 | 7–9 | 12–36 | 70–110 |

- Draft about 4.5–6.5 m. Displacement hull: top speed about 17–22 kn, cruise 13–16 kn, range at cruise about 5,000–8,000 nm.
- More than 12 guests normally requires a passenger-yacht code; state the guest count and code assumption.
- Novel propulsion or speed beyond the range → one-line technical justification.
- Label the block: CONCEPT SPECS — NOT A REAL VESSEL.

**CONCEPT\_ASSUMPTION (v6):** every number that is not derived from a check is tagged `CONCEPT_ASSUMPTION` in the JSON (e.g. `"top_speed": {"value": 19, "unit": "kn", "basis": "CONCEPT_ASSUMPTION"}`). Spec cards in the film may show only LOA, decks and guest count, always with “· CONCEPT”.

**Plausibility notes:** each innovation with an obvious engineering risk (glass below the waterline, large hull openings, long cantilevers) gets a 2–3 line note: the risk, how a real design would address it (e.g. thick laminated glass in a reinforced frame, watertight doors closed at sea above a set speed), and what the film must not show (e.g. doors open while running at full speed). The film respects these notes.

**Operational limits (v6.2):** every innovation with a plausibility note also gets a machine-readable entry in `operational_limits` of the canonical design, e.g. `{"feature": "hull_doors_open", "allowed_when": {"at_anchor_only": true, "max_speed_kn": 0}}`, `{"feature": "helideck_landing", "allowed_when": {"max_speed_kn": 6, "max_sea_state": 3}}`. These limits are checked in three places: Phase 3B (hero moment, money shots, obstacles), Phase 6C (every shot’s `operational_context` → `validation.operational`; a violation is BLOCKED) and Phase 8 (motion prompts may not describe a speed or action beyond the limit).

### Step 3 — Exterior

Order: silhouette → bow → hull shape and openings → superstructure (decks, setbacks, recesses) → glazing → mast / radar → helideck(s) → stern → swim platform / water access.

- **Colors:** max 3 exterior colors (hull · superstructure · accent), each name + HEX.
  - Right: “Hull: Basalt Graphite #2B2E33 · Superstructure: Salt White #EEECE6 · Accent: Oxidized Bronze #6E5A43”
- **Innovations:** the 3 from Phase 2, each with exact position on the yacht.
- **Exterior zones:** EXT01… one line each (foredeck, bow terrace, helideck, sun deck, pool deck, aft decks, sea hall doors, swim platform…).

### Step 4 — Deck by deck

One line per deck from tank top to top deck: fore / mid / aft spaces.

### Step 5 — Interior rooms (INT01–INT12)

Cover the film’s room tour. Recommended set:

| ID | Room |
| --- | --- |
| INT01 | Owner’s deck / owner’s suite |
| INT02 | VIP suite |
| INT03 | Main salon |
| INT04 | Formal dining |
| INT05 | Sky lounge / observation lounge |
| INT06 | Wellness: spa, hammam, treatment room |
| INT07 | Gym |
| INT08 | Cinema / media room |
| INT09 | Beach club / sea hall |
| INT10 | Wheelhouse |
| INT11 | Main lobby and staircase |
| INT12 | Guest cabin |

Each room: deck · position · size feel · max 5 materials with finish · color palette (HEX) · one signature visual detail · natural light source (window type). Example:

- Right: “INT03 Main salon · main deck midships · full beam, 4 m ceiling · bleached oak floor, travertine wall, bronze mesh ceiling, wool bouclé seating, smoked glass · palette #E9E3D8 #8C7B66 #2B2E33 · a 9 m curved window following the hull arch · daylight through floor-to-ceiling glass on both sides.”

### Step 6 — Lighting signature (night)

One sentence: light sources, Kelvin, what glows and what stays dark.

### Step 7 — YACHT ANCHOR (most important output)

- 60–80 words · English · present tense · exterior of the FINISHED yacht only.
- Order: type + LOA → silhouette → bow → hull + color → superstructure + glazing + color → 3 innovations → stern.
- No quality adjectives, brands, light, environment or camera.

Wrong:

```
A stunning futuristic 150m superyacht with elegant lines and luxurious decks at sunset
```

Right:

```
A 148-metre motor superyacht whose hull is one continuous arch rising from a low reverse-raked bow to a 22-metre-high stern. Basalt-graphite hull. White superstructure recessed into the arch in four stepped terraces with continuous dark glazing. Twin hull doors at midships open outward into floating terraces. A glass observation blister below the bow waterline. Flush helideck on the foredeck, no visible mast, squared stern with a full-width water platform.
```

### Step 7B — Geometry anchor and surface states (v6)

The finished YACHT ANCHOR describes paint, glass and finishes, so pasting it into raw-steel shots makes the model paint the hull too early. v6 splits it:

| Text block | Contains | Never contains | Used for |
| --- | --- | --- | --- |
| **GEOMETRY ANCHOR** (50–70 words) | type, LOA, silhouette, bow, hull form, deck count and setbacks, opening positions, innovation shapes, stern | colors, materials, glass tint, finishes, light | every shot of the yacht from BS05 to BS12 |
| **SURFACE STATE** (one sentence per BS06–BS12) | the surface at that stage: steel and primer, raw aluminium, protective film, filler, final paint | shape information | paired with the geometry anchor |
| **FINISHED SURFACE** (20–30 words) | final colors with names, glazing tint, metal finishes | shape information | BS11–BS12 and the model sheet |

The YACHT ANCHOR of Step 7 = GEOMETRY ANCHOR + FINISHED SURFACE. It is used only for the model sheet and BS11–BS12.

Example GEOMETRY ANCHOR:

```
A 148-metre motor superyacht whose hull is one continuous arch rising from a low reverse-raked bow to a 22-metre-high stern. Superstructure recessed into the arch in four stepped terraces with continuous window bands. Twin rectangular hull doors at midships. A rounded observation blister below the bow waterline. Flush helideck on the foredeck, no mast, squared stern with a full-width water platform.
```

Example SURFACE STATE (BS08):

```
Hull surfaces in bare welded steel with red-oxide primer; superstructure in raw unpainted aluminium; window openings empty; no paint, no glass.
```

Example FINISHED SURFACE:

```
Basalt-graphite gloss hull, salt-white superstructure, continuous dark-tinted glazing, oxidized-bronze accents on the terrace edges and hull door frames.
```

### Step 7C — State geometry per build state (v6.1)

The full GEOMETRY ANCHOR describes the finished structure. In early build states much of it does not exist yet, so pasting it in would make the model build the superstructure too early. Each build state therefore has a **STATE GEOMETRY** sentence that names only the parts that exist and states the important absences explicitly.

| State | Parts that exist | Identity block used | Example STATE GEOMETRY |
| --- | --- | --- | --- |
| BS05 | separate asset: 1:25 tank model of the hull only | STATE GEOMETRY (model) | a white 1:25 model of the hull only: low reverse-raked bow, arched sheer line, no superstructure |
| BS06 | keel and first bottom blocks | STATE GEOMETRY | the first welded bottom blocks of a 148-metre hull laid along the keel line, no sides above the waterline, no decks, no superstructure |
| BS07 | full hull shell, open main deck | STATE GEOMETRY | a complete 148-metre hull with a low reverse-raked bow and an arched sheer line rising to a 22-metre stern, open main deck, twin rectangular hull door openings at midships, no superstructure |
| BS08 | hull + superstructure structure, no glazing | STATE GEOMETRY | the hull with the superstructure recessed into the arch in four stepped terraces, window openings empty, no mast, no helideck surface |
| BS09–BS12 | complete structure | GEOMETRY ANCHOR (full) | — |

Rules: STATE GEOMETRY is compiled from the BuildState in the canonical `build_states` (its `present_parts` and `absent_parts`), never written freely; it never adds parts that the canonical design does not have; constraints before their `from_state` are `not_yet_built` and constraints after their `until_state` are `not_applicable` — neither is checked. BS05 (tank model) is a separate asset type: same hull lines, scaled, without superstructure — its frames are never compared with full-size yacht references.

v6.2: each build state is stored as a **BuildState** object with `present_parts[]` and `absent_parts[]` (schema 2). The STATE GEOMETRY sentence is compiled by code from these lists — present parts as positive phrases, absent parts as “no …” phrases. Validators check the lists, never the words of the compiled sentence.

### Step 8 — Zone and room anchors

- One EXTERIOR ZONE ANCHOR per EXT (30–50 words).
- One INTERIOR ANCHOR per INT (30–50 words): room, materials, colors, signature detail, window type. No people, no camera, no light mood words.

### Step 9 — Build states (BS01–BS12)

One English sentence each, describing ONLY the yacht’s physical state; always start with “the yacht”.

| ID | Sentence (insert into prompts) |
| --- | --- |
| BS01 | the yacht as a graphite pencil side-profile sketch on white paper |
| BS02 | the yacht as a concept board of loose sketches, material swatches and a silhouette study, no text |
| BS03 | the yacht as a glowing wireframe 3D model with flow lines over the hull on a dark screen |
| BS04 | the yacht as a matte clay-grey 3D render, no colors, no glass |
| BS05 | the yacht as a 1:25 white scale model of the hull only |
| BS06 | the yacht as the first welded steel keel blocks in red-oxide primer |
| BS07 | the yacht as a complete steel hull in red-oxide primer, open deck, no superstructure |
| BS08 | the yacht as a steel hull joined to raw unpainted aluminium superstructure, scaffolding along both sides |
| BS09 | the yacht with all glazing fitted, windows covered by white protective film |
| BS10 | the yacht entirely in grey filler and primer, masking tape on edges |
| BS11 | the yacht in final colors, dry, on a cradle, protective film removed |
| BS12 | the completed yacht in final colors, afloat |

Interior rooms get three set states, used in Phase 5: `bare` (steel, insulation, cable trays) · `fitout` (joinery installed, floors covered, protective wrap) · `finished`.

Grouping (v6.2): BS01–BS04 are **design states** (drawings and screens) and never show a photoreal yacht; BS05 is the **scale-model** asset; BS06–BS08 are **build states** that use their STATE GEOMETRY (Step 7C) + SURFACE STATE; BS09–BS12 use the full GEOMETRY ANCHOR + SURFACE STATE (or FINISHED SURFACE for BS11–BS12).

### Step 10 — Model sheet (MS1–MS8)

Each prompt = YACHT ANCHOR + view + “neutral light-grey studio background, soft even light, orthographic product view, no text”.

| ID | View |
| --- | --- |
| MS1 | port side profile, exact side view |
| MS2 | 3/4 bow from port, eye level |
| MS3 | 3/4 stern from starboard, eye level |
| MS4 | bow head-on |
| MS5 | stern head-on |
| MS6 | top-down plan view |
| MS7 | aerial 3/4 from high port bow |
| MS8 | close-up of innovation N1 |

Render MS1 first with both image tools; keep the better one and use it as reference for MS2–MS8 (Phase 0 routing).

### Step 11 — Canonical Design JSON and constraint registry (v6)

The Canonical Design JSON is the machine-readable definition of the yacht and the single source of truth. It is built in two revisions so that nothing depends on itself.

**Sequence (v6.1):**

1. **Canonical draft (revision 0)** from Steps 2–9: dimensions, deck heights and setbacks, estimated landmark positions, surfaces, state geometry, rooms. No traced curves yet.
2. **Anchors** (geometry, surface states, finished surface, state geometry) are written from revision 0.
3. **Model sheet** MS1–MS8 is rendered from the anchors.
4. **Trace and correct:** the profile curve, plan outline, deck outlines and landmark positions are traced from the approved MS1 and MS6; deviations from revision 0 are listed.
5. **Canonical revision 1** = revision 0 corrected by the traced data. If a correction changes a P0 fact (e.g. the deck count or bow type differs), the model sheet is rejected and re-rendered instead of changing the design.
6. **Consistency check:** anchors vs revision 1 (RULE), model sheet vs revision 1 landmarks (overlay, human).
7. **Design lock:** canonical revision 1, constraint registry, anchors and model sheet are locked together in one lock record.

Contents:

- **Dimensions:** LOA, LWL, beam, draft, deck heights and setbacks (with `basis`).
- **Profile curve:** 20–40 points (x as fraction of LOA, y in metres) for the sheer / arch line and the superstructure outline in side view, traced from the approved MS1.
- **Plan outline:** 20–40 points of the hull outline in top view, traced from MS6.
- **Deck outlines:** per deck, start and end as fractions of LOA.
- **Landmarks:** position of every identity feature as fractions of LOA and height (bow tip, hull doors, blister, helideck, terraces, stern platform).
- **Surfaces:** finished colors (HEX), glazing tint, metal finishes; surface state per BS.
- **Rooms:** INT list with deck, position and window type.

Constraint registry: generated from the canonical JSON, at least 12 constraints — every P0 identity feature, every landmark as P1, key finish details as P2. Each constraint has: `id`, `priority`, `statement`, `check` (how it is verified: reference landmark, vision question, count) and `visible_from` (which views or shot sizes can see it).

Partial example (full structure: schema 1 in Common standards):

```json
{
  "canonical": {"revision": 1, "hash": "sha256:…",
    "dimensions": {"loa": {"value": 148, "unit": "m", "basis": "CONCEPT_ASSUMPTION"}},
    "profile_curve": [[0.0, 3.1], [0.05, 4.0]],
    "landmarks": {"hull_doors_center_x": 0.52, "blister_x": 0.03, "helideck_x": 0.18}},
  "constraints": [{"id": "C-P0-001", "priority": "P0", "statement": "8 decks above the hull", "check": "count_decks", "visible_from": ["MS1", "MS2", "WS"]}]
}
```

### Output

```json
{
  "yacht_bible": {
    "name": "Noctiluca",
    "specs": {"loa": {"value": 148, "unit": "m"}, "beam": {"value": 21, "unit": "m"}, "decks": 8, "guests": 24, "crew": 72},
    "decks": [{"name": "Main", "height": 3.9, "setback": 0}],
    "colors": [{"role": "hull", "name": "Basalt Graphite", "hex": "#2B2E33"}],
    "innovations": [{"id": "N1", "position": "…"}],
    "exterior_zones": [{"id": "EXT01", "text": "…"}],
    "rooms": [{"id": "INT03", "deck": "Main", "materials": ["…"], "palette": ["#E9E3D8"]}]
  },
  "anchors": {"yacht": "…", "exterior": {"EXT01": "…"}, "interior": {"INT03": "…"}},
  "build_states": {"BS01": "…"},
  "model_sheet": [{"id": "MS1", "prompt": "…"}]
}
```

### Gates

- **RULE:** LOA 110–180 m; specs in range or justified; YACHT ANCHOR 60–80 words; zone and room anchors 30–50 words; no forbidden words, no blocklisted names; ≤ 3 exterior colors with valid HEX; 3 innovations positioned; ≥ 10 rooms; name not near-matching the source or blocklist (string distance).
- **JUDGE:** must-avoid satisfied; anchors are geometric, not adjectival; rooms are distinct from each other (≥ 8/10).
- **VISION (after model sheet):** MS2–MS8 similarity to MS1 ≥ threshold; no text; deck count visible matches `decks`; similarity to images of the source yacht and to a reference set of well-known superyachts BELOW the copy threshold (novelty check).
- **Stop:** DESIGN LOCK — REVIEW\_KEY stops for human approval of the model sheet.

v6 additions to the gates: GEOMETRY ANCHOR contains no color, material or finish words (RULE); every P0 constraint is visible in at least one model-sheet view (MS1–MS8) or a designated detail sheet (interior P0 constraints: in the room board) (VISION + human); visible canonical identity landmarks agree with the approved model sheet (vision and human review; uncertainty remains explicit); the design lock record is written with hash and approver.

### Quality gate

- [ ] Name original; LOA 110–180 m; specs plausible
- [ ] Silhouette and 3 innovations visible in MS1
- [ ] ≥ 10 rooms with materials, palette, signature detail
- [ ] YACHT ANCHOR 60–80 words, geometric only
- [ ] Build states BS01–BS12 and room set states defined
- [ ] Model sheet consistent; novelty vision check passed

## Phase 3R — Reference pack

A curated, versioned set of reference images that every image and video call draws from. It replaces custom model training: GPT Image 2 and Nano Banana are steered by references, not fine-tuning.

You are the pipeline’s reference librarian. **Input:** approved model sheet · `yacht_bible`.

### Contents

| Group | Images |
| --- | --- |
| Views | MS1–MS8 (approved) |
| Detail sheets | bow, stern, glazing pattern, each innovation close-up, helideck, swim platform |
| Color sheet | three exterior colors as clean swatches on the hull material |
| Room boards | per INT: one approved finished-state image + a material board (no text) |
| Turntable | one 3–8 s orbit of the finished yacht on neutral background, generated from MS frames; its best 3 s used as a video reference for Omni 1.1 Flash |
| Style refs | added after Phase 5L |

### Reference budget per call

| Tool / shot type | References attached |
| --- | --- |
| Nano Banana Pro, exterior yacht shot | approved reference image (edit input) + 3 nearest MS views + 1–2 detail sheets + color sheet + LOC ref + 2 style refs (≤ 14) |
| Nano Banana Pro, interior shot | SET ref + room board + material board + 2 style refs |
| GPT Image 2 | approved yacht reference or SET ref as edit input + the 2–4 most relevant references |
| Veo 3.1 reference mode | 3 images max: nearest MS view + key detail + style ref |
| Omni 1.1 Flash | image references + up to 3 s video reference (turntable or previous clip) |

### Gates

- **RULE:** every listed group present; every image traceable to an approved source; no text in any image.
- **VISION:** detail sheets and turntable similarity to MS1 ≥ threshold; color sheet matches HEX (ΔE within tolerance).

**Keys:** `ref_pack{views[], details[], colors, rooms{}, turntable, style[]}`.

## Phase 3B — Visual story engine

Without a voice, the story must live in images, music and a handful of text cards. Phase 3B gives the film a protagonist, a question, rising obstacles, a hidden reward and a rhythm that keeps viewers to the end.

You are the story architect for a wordless short documentary. **Input:** `dna` · `yacht_bible` · {video\_length}.

### Absolute rules

- Every story beat must be SHOWABLE. If it needs words to be understood, redesign it or give it one text card of ≤ 6 words.
- Drama exists only in the concept layer, labelled \[CONCEPT STORY\]. Never involve the source yacht, real yards or real people.
- No disasters: no injuries, fires, sinkings. Tension comes from physics, precision, weather windows and time.
- No readable faces: people are hands, silhouettes, backs, reflections.
- The finished yacht is never shown whole in beautiful light before the hero beat. Finished interiors are revealed only in the life-at-sea act.
- Every open loop closes before the end card.

### Step 0 — Story type

| Type | Core | Fits |
| --- | --- | --- |
| A — AGAINST PHYSICS | the boldest innovation could fail; the film proves it works | engineering-led concepts |
| B — METAMORPHOSIS | raw red steel becomes a flawless object, stage by stage | craft-led concepts |
| C — IMPOSSIBLE SHAPE | a silhouette nobody has built; the film shows it taking form | silhouette-led concepts |
| D — LESS IS MORE | bigger yet quieter, larger yet lighter; the film tests the paradox | sustainability-led concepts |

v6 adds **E — PROCESS / SPECTACLE**: no obstacles; tension comes from scale, transformation and the reveal ladder alone. Allowed for films ≤ 60 s or when the design has no single risky innovation. Central question optional; reveal ladder and loops still required.

### Step 1 — Characters (with CHARACTER ANCHORS, 20–30 words, English)

| Character | Role | Identification |
| --- | --- | --- |
| THE YACHT | protagonist; its “desire” is innovation N1; its weakness is N1’s risk | Yacht Anchor + build states |
| THE HANDS | anonymous designer; opens Act 1, returns in Act 4 (callback) | graphite pencil, leather sketchbook without logo, steel scale ruler |
| THE CREW | yard craftsmen; seen from behind | fixed coverall color, e.g. burnt orange #E8652B, white hard hats |
| THE CAPTAIN | sea-trial master; seen from behind on the bridge | plain navy shirt, hand on a brushed-steel throttle |

```
A designer's hands seen from above, no rings, no watch, holding a graphite pencil over a leather-bound sketchbook without logo, a steel scale ruler beside it.
```

### Step 2 — Central question

A yes / no question tied to the riskiest innovation, answerable on screen in Act 4. It may appear once as a text card (≤ 6 words).

- Wrong: “How beautiful will it be?”
- Right: “Can a 148 m hull open its sides at sea?” → text card: “CAN IT OPEN AT SEA?”

### Step 3 — Stakes

What is lost if the answer is no (an innovation removed, a deck cut, a delay).

### Step 4 — Three escalating obstacles

Each obstacle follows four visible beats: PROBLEM → VISIBLE SIGN → ACTION → VISIBLE PROOF.

| Stage | Obstacle example | Visible sign | Action → proof |
| --- | --- | --- | --- |
| Tank test (BS05) | bow spray over the observation blister | water sheets across the model’s bow glass | bow lengthened → water parts cleanly |
| Construction (BS07–BS08) | superstructure lift in a short wind window | taut windsock, crane paused mid-air | lift at dawn calm → modules seat on their pins |
| Hull doors (BS09–BS10) | door seal test | water jets leak through the seam | seals refitted → door wet and dry inside |
| Completion (BS11–BS12) | launch needs one high tide | water creeping up a dock ladder | launch at the peak → hull floats, water rolls outward |
| Sea trials | vibration at top speed | ripples in a glass of water on a salon table | propeller tuned → the water surface goes still |

Pick 3 by dosage (Step 11); obstacle 2 near the film’s midpoint; obstacle 3 resolves the central question.

### Step 5 — Visual countdown

One visible time limit across the film without readable text: a dock ladder with the waterline rising, a moon phase in the sky, the light changing from dawn to night across a sequence.

### Step 6 — Open loops (plant → payoff)

3–5 loops; plant-to-payoff distance ≥ 20% of the film.

| Loop | Plant | Payoff |
| --- | --- | --- |
| The line | A1: one pencil line drawn | A4: the real hull edge matches the line (match cut) |
| The doors | A2: closed hull doors under scaffolding | A4: doors open at sea, terraces deployed |
| The glass | A3: a glass placed on a bare salon table | A4: still water in the glass at full speed |
| The empty room | A2: INT03 bare steel | A4: INT03 finished, same camera position |

### Step 7 — Reveal ladder

| Level | The viewer sees | Used in |
| --- | --- | --- |
| H1 | details only: macro of materials, welds, glass edges | all acts |
| H2 | part of the yacht: bow or stern, cropped frame | A2–A4 |
| H3 | silhouette: backlight, haze, smoke | A2–A3 |
| H4 | full profile but unfinished or partly hidden | A2–A3 |
| H5 | the whole finished yacht in beautiful light | only from the hero beat |

Rooms follow the same logic: `bare` and `fitout` states in A2–A3; `finished` only in A4.

### Step 8 — Motifs

- **Visual motif (≥ 3 times):** pencil line → weld seam → sheer line → wake.
- **Sound motif:** a metallic percussion figure in the music in A2 returns as the beat under the hero in A4.
- **First–last rhyme:** first and last frames share a composition (pencil profile on paper → the real profile at sea).

### Step 9 — Money shots (6–9)

At least one per act and at least two interiors. Suggestions: slow-motion weld sparks (macro) · superstructure lowered onto the hull · protective film peeled from a glass wall · shed doors opening, light flooding in · first contact with water · aerial pull-back revealing the wake · hull doors opening at sea · golden-hour light sweeping across the main salon · deck lights coming on deck by deck at blue hour.

v6.2: the hero moment, every money shot and every obstacle are checked against the canonical `operational_limits` before the story is approved (e.g. doors open only at anchor, so “doors opening at sea” is staged at anchor and a running shot shows the doors closed).

### Step 10 — Act structure (wordless film)

| Act | Share | Content | Build states / rooms |
| --- | --- | --- | --- |
| A1 Idea & design | \~15% | hands, sketch, concept board, digital model, tank test | BS01–BS05 |
| A2 Construction | \~30% | keel, hull, superstructure lift, outfitting, rooms bare → fit-out | BS06–BS10, sets `bare` / `fitout` |
| A3 Completion & launch | \~15% | paint reveal, shed doors, launch | BS11–BS12 |
| A4 Life at sea | \~40% | sea trials exterior, interior tour room by room, deck life, hero | BS12, sets `finished` |

### Step 11 — Dosage by length

| Length | Obstacles | Loops | Money shots | Rooms in tour | Flash-forward |
| --- | --- | --- | --- | --- | --- |
| ≤ 60 s | 2 | 2 | 4 | 3–4 | required |
| 60–180 s | 3 | 3 | 6–7 | 6–8 | recommended |
| > 180 s | 3 + 1 per act | 4–5 | 8–9 | 10–12 | optional |

v6: the dosage table is a recommendation. The story architect may deviate by one step in any column when it serves the music and the story, and must state the reason in `story.notes`. Story type E uses 0 obstacles.

### Step 12 — Emotion curve

Score every beat 1–10; ≥ 2 valleys; peak at the hero. The curve is the brief for music selection in Phase 4M.

### Output

```json
{
  "story": {"type": "A", "central_question": "…", "question_card": "CAN IT OPEN AT SEA?", "stakes": "…",
    "obstacles": [{"id": "O1", "beat": "B4", "problem": "…", "sign": "…", "action": "…", "proof": "…"}],
    "countdown": "…", "loops": [{"id": "L1", "plant": "B3", "payoff": "B15"}],
    "reveal_plan": {"B1": "H2"}, "motifs": {}, "money_shots": ["…"], "emotion_curve": {"B1": 7}},
  "anchors": {"character": {"HANDS": "…", "CREW": "…", "CAPTAIN": "…"}}
}
```

### Gates

- **RULE:** dosage matches length; loops separated ≥ 20%; no H5 before the hero beat; no `finished` room before A4; ≥ 2 valleys, peak at hero; every obstacle labelled \[CONCEPT STORY\]; character anchors 20–30 words.
- **JUDGE:** every beat showable without words; obstacles plausible for shipbuilding; central question answerable on screen (≥ 8/10).

### Quality gate

- [ ] Central question visual and answerable
- [ ] Obstacles show sign and proof
- [ ] Reveal ladder and room states respected
- [ ] Motifs and first–last rhyme planned
- [ ] Emotion curve ready for music selection

## Phase 4 — Visual script (no voiceover)

Phase 4 writes the film as a sequence of visual beats, sparse text cards and a precise music brief. There is no narration: every beat must work with the sound off except for music and effects.

You are a senior documentary scriptwriter for wordless films on {platform}. **Input:** `story` (3B) · `yacht_bible` · {video\_length} · {tone\_notes}.

### Absolute rules

- No voiceover, no dialogue, no lyrics.
- Each beat is described as what the camera sees and hears, not what the viewer should feel.
- Text cards are rare: ≤ 1 card per 10 s on average, ≤ 6 words each, ≥ 1.5 s on screen.
- Text cards may show only: stage labels, concept specs with “CONCEPT”, the yacht’s name, the central-question card, the disclaimer and end card. No real-world facts, names or dates from the article.
- The first 3 s carry the hook; the yacht’s name appears no earlier than the end of A1.

**Forbidden in cards and titles:** extraordinary, incredible, amazing, jaw-dropping, breathtaking, ultimate, luxury redefined, next level, dream, billionaire, insane, epic, game-changer.

- Wrong card: “THE MOST INCREDIBLE YACHT EVER”
- Right card: “148 M · CONCEPT”

### Step 1 — Opening hook (first 3 s)

The hook is an image (plus an optional ≤ 6-word card). It must contain a concrete anchor: a shape, a scale cue, a number or a name.

| Type | Wrong | Right |
| --- | --- | --- |
| A — SCALE SHOCK | a generic wide shot of a shipyard | a welder’s silhouette dwarfed under a 22 m red steel stern; card “148 M” |
| B — COLD MOTIF | random sketches | a single pencil line drawn across white paper in macro, sound of graphite |
| C — SCENE-SETTER | “a day at the yard” | dawn, shed doors 40 m high begin to part, light spills in |
| D — CONTRAST | a slow montage | cut: raw red steel → the same angle, finished hull at sea |
| E — FLASH-FORWARD | the full finished yacht | 2 s of hull doors opening at sea, cropped (H2), hard cut to the blank page |
| F — MYSTERY | “something new is coming” | water sheets over a model’s glass bow in the tank; card “IT FAILED FIRST.” |

### Step 2 — Beat sheet (B0–B16)

| Beat | Act | Content | Build state / room | Story layer |
| --- | --- | --- | --- | --- |
| B0 | A1 | flash-forward (optional) | BS12, H2 | opens the question |
| B1 | A1 | hook | — | — |
| B2 | A1 | the hands, first line | BS01 | plant loop “the line” |
| B3 | A1 | concept board, silhouette emerges | BS02 | name card at end of A1 |
| B4 | A1 | digital model, flow lines | BS03–BS04 | — |
| B5 | A1 | tank test | BS05 | obstacle 1 (if dosed) |
| B6 | A2 | keel blocks, sparks | BS06 | crew introduced |
| B7 | A2 | hull rises | BS07 | PROGRESS shots begin |
| B8 | A2 | superstructure lift | BS08 | obstacle 2 near midpoint; silence |
| B9 | A2 | rooms bare, then fit-out | BS08–BS10, sets bare / fitout | plant “empty room” |
| B10 | A2 | glazing, doors, seal test | BS09–BS10 | — |
| B11 | A3 | paint, film peeled, color reveal | BS11 | satisfying shots |
| B12 | A3 | shed doors open, launch | BS12 | obstacle 3 if launch-based; countdown resolves |
| B13 | A4 | sea trials exterior: tracking, aerial, speed | BS12 | answer approaches |
| B14 | A4 | interior tour, room by room | sets finished | payoff “empty room” |
| B15 | A4 | the answer: innovation working at sea | BS12 | central question answered |
| B16 | A4 | hero moment, then end card | BS12, H5 | payoff “the line”, first–last rhyme |

Each beat records: duration target (s) · purpose (one line) · visual description · text card or “none” · sound intent (music section + SFX) · emotion (1–10).

### Step 3 — Interior tour rules (B14)

- Order follows a walkable path (lobby → salon → dining → sky lounge → owner’s suite → spa → beach club) so continuous moves feel real.
- Each room gets 1–3 shots: one wide establishing, one detail of its signature element, optional a short continuous move into the next room.
- People only as silhouettes or backs; tables set minimally; no logos, books with titles, screens with content.
- Every room’s finished shot matches the camera of its earlier `bare` shot when the “empty room” loop is used.

### Step 4 — Text card plan

List every card with beat, words, duration and type (STAGE · SPEC · NAME · QUESTION · DISCLAIMER · END). Spec cards always end with “· CONCEPT”.

### Step 5 — Music brief (input to Phase 4M)

| Field | Content |
| --- | --- |
| Length | {video\_length} (± 5% before editing) |
| Structure needed | intro ≤ 3 s or a cold start · build through A2 · a drop or break before B8 · lift at B12 · widest section for B13–B15 · quiet or sparse section for B16 · clean ending |
| Mood per act | A1 curious, precise · A2 driving, industrial · A3 rising · A4 open, expansive, then still |
| BPM | 80–110 typical; one tempo or a planned tempo change at A3 |
| Instrumentation | per channel bible; metallic percussion motif in A2 |
| Exclusions | no vocals, no lyrics, no recognizable melodies, no comedic or aggressive genres |

### Step 6 — Sound-effects plan

Per beat: key SFX (graphite on paper, arc welding, crane chains, air tools, spray guns, film peeling, water rush, engines, wind) and ambience (studio, hall, sea). Native ambience from the video models may be used as a bed.

### Output

```json
{
  "script": {
    "logline": "…",
    "hook_type": "E",
    "beats": [{"id": "B8", "act": "A2", "target_sec": 7, "purpose": "…", "visual": "…", "card": null, "sound": "silence then chain clank", "emotion": 8, "bs": "BS08"}],
    "text_cards": [{"beat": "B7", "text": "STAGE 07 — STEEL", "type": "STAGE", "sec": 1.8}],
    "music_brief": {"length": 120, "bpm": [88, 104], "sections": ["cold start", "build", "break before B8", "lift B12", "wide B13-B15", "sparse B16"]},
    "sfx_plan": [{"beat": "B6", "sfx": ["arc welding", "hall hum"]}]
  }
}
```

### Gates

- **RULE:** no voiceover fields; cards ≤ 6 words, ≥ 1.5 s, ≤ 1 per 10 s on average; spec cards end with “· CONCEPT”; no forbidden words, no blocklisted names, no article facts; beat targets sum to {video\_length} ± 5%; disclaimer and end card present; no `finished` room before B13.
- **JUDGE — wordless clarity (≥ 8/10):**

| Criterion | Points |
| --- | --- |
| Hook works in 3 s with sound off | 2 |
| Central question understood by \~10 s (image or card) | 2 |
| Each obstacle reads as sign → proof without words | 2 |
| Interior tour feels like a walkable path | 2 |
| Hero is the most beautiful moment and the first full reveal | 2 |

### Quality gate

- [ ] No narration anywhere
- [ ] Hook type chosen and concrete
- [ ] Beat sheet covers idea → design → construction → completion → sea, exterior and interior
- [ ] Cards sparse and compliant
- [ ] Music brief and SFX plan complete

## Phase 4M — Music selection and timing lock

In a wordless film the music is the narrator. Phase 4M picks licensed tracks from {music\_library} that match the emotion curve, edits them to the exact length on musical boundaries, and turns their structure into the timing grid every shot snaps to. This is the TIMING LOCK.

You are the music supervisor. **Input:** `script.music_brief`, `script.beats` (Phase 4) · `story.emotion_curve` (3B) · channel bible music signature · {music\_library}.

### Absolute rules

- Only tracks with a clear commercial license valid for {platform}; store license ID and terms.
- Instrumental only; no vocals or lyrics (verified by measurement, not by tags alone).
- Never imitate or choose sound-alikes of famous songs.
- Edits only on musical boundaries (bars / phrases); never cut mid-phrase.
- The final music length equals {video\_length} exactly, with a natural ending.

### Step 1 — Candidate search

Query the library with the brief: mood per act, genre, BPM range, instrumental, duration ≥ {video\_length} − 10%. Keep 10–20 candidates.

### Step 2 — Analysis (Python worker)

For each candidate measure (e.g. with librosa or a music-structure model): BPM, beat and downbeat grid, section boundaries (intro / build / break / drop / outro), energy curve (RMS per bar), vocal presence, loudness, ending type.

### Step 3 — Scoring

| Criterion | Measure | Weight |
| --- | --- | --- |
| Fit to the emotion curve | correlation between bar energy and the beat emotion curve | 30% |
| Structure fit | sections can align with act boundaries; break available before B8; widest section can cover B13–B15; sparse part for B16 | 25% |
| Length fit | editable to {video\_length} on phrase boundaries | 15% |
| Channel signature | genre / instrumentation match | 10% |
| JUDGE listening proxy | mood vs brief, not resembling a famous song | 20% |

Hard fails: vocals detected, license unclear, sound-alike suspicion. If no single track scores ≥ 0.75, combine two tracks (A1–A2 and A3–A4) that share key or meet at a natural break, crossfaded over 1–2 bars.

### Step 4 — Edit to length

- Remove or repeat whole 4- or 8-bar phrases; crossfade 1–2 beats at each edit.
- Keep the intro ≤ 3 s or start cold on a strong beat.
- Keep the natural ending; never fade out mid-phrase.

### Step 5 — Beat-to-music mapping

- Assign each story beat B0–B16 to a span of bars; act boundaries fall on section boundaries.
- B8 (lift / obstacle 2) sits on the break: music drops out 0.5–1 s.
- B12 (launch) sits on a lift; B13–B15 on the widest section; B16 on the sparse ending.
- Money shots start on downbeats or accents (hits).

### Step 6 — Cut grid (the timing lock)

- Allowed cut points: every downbeat; every beat inside fast montage sections (A2); half-beats only for ≤ 2 rapid sequences.
- Each beat gets exact `start` and `end` in seconds; total = {video\_length}.
- Accent list: timestamps of hits, drops and lifts that Phase 6 must place cuts or motion peaks on.

### Output

```json
{
  "music": {"tracks": [{"id": "MUS01", "library_id": "…", "license": "…", "bpm": 96, "key": "D minor"}],
    "edit": [{"op": "remove_bars", "from": 33, "to": 40}], "file": "music_edit.wav", "stems": ["…"]},
  "timing": {"total": 120.0,
    "beats": [{"id": "B8", "start": 41.25, "end": 48.75, "section": "break"}],
    "cut_grid": "cutgrid.json",
    "accents": [{"t": 48.75, "type": "drop"}, {"t": 71.25, "type": "lift"}]}
}
```

### Gates

- **RULE:** license present; no vocals; final length = {video\_length} ± 0.05 s; every edit on a bar boundary; every story beat has start / end; acts on section boundaries.
- **MEASURE:** energy-to-emotion correlation ≥ 0.6; break present before B8; sparse section at B16.
- **JUDGE:** mood matches brief; no resemblance to a famous track (≥ 8/10).

### Quality gate

- [ ] Licensed, instrumental, original-sounding track(s)
- [ ] Edited to exact length on musical boundaries
- [ ] Beats mapped to sections; accents listed
- [ ] Cut grid exported — timing locked

## Phase 5 — Locations and interior sets

Phase 5 designs every place the film visits — studio, yard, sea — and every interior room in its three states, each with a fixed anchor and a reference image so the same place looks the same in every shot.

You are the production designer and location scout. **Input:** `yacht_bible` (rooms, specs) · `script.beats` · `timing` (4M).

### Absolute rules

- Every location is fictional but physically realistic; never name a real shipyard, marina, company or landmark; generic geography is allowed (“Mediterranean coast”, “northern fjord”, “tropical limestone archipelago”).
- No signage, text, logos, flags with emblems or company colors anywhere.
- Every location physically fits a 110–180 m yacht in the relevant state (scale check).
- Describe with dimensions, materials and light sources, never with mood adjectives.
- One key light per location, named with direction and Kelvin.

### Step 1 — Exterior locations

| ID | Location | States | Default key light |
| --- | --- | --- | --- |
| LOC01 | Design studio | BS01–BS02 | north-window daylight 5600K side key + warm desk lamp 3200K |
| LOC02 | Visualization room | BS03–BS04 | screen glow 6500K, room dark |
| LOC03 | Towing tank | BS05 | overhead cool LED 4500K, still-water reflections |
| LOC04 | Construction hall (≥ 220 m long, ≥ 60 m to roof) | BS06–BS10 | sodium high-bay 2200K top-down + daylight shafts 5600K |
| LOC05 | Paint hall | BS10–BS11 | diffused white LED 6500K, overspray haze |
| LOC06 | Launch: floating dock or ship lift at the hall exit | BS12 launch | golden hour 3500K low side sun |
| LOC07 | Sea-trial waters | BS12 | midday hard sun 5600K, deep blue water |
| LOC08 | Hero location (from `hero_moment`) | BS12 | blue hour 8000K ambient + yacht’s own 2700K lights |
| LOC09 | Anchorage at night (optional) | BS12 | moonlight 4000K + deck lights 2700K |

Scale check example: a 148 m hull with superstructure is about 40 m from keel to top deck; LOC04 must be ≥ 170 m long and ≥ 50 m high, or the build is under a partial roof with the top outside.

### Step 2 — Interior sets

Every INT room has three sets. The set anchor = room anchor (Phase 3) for `finished`, or the room’s geometry + the state sentence:

| State | State sentence (English, inserted into prompts) |
| --- | --- |
| bare | the room as raw steel structure with grey insulation panels, cable trays, temporary work lights, no finishes |
| fitout | the room with joinery and wall panels installed, floors under protective boards, furniture wrapped in white film |
| finished | (room anchor from Phase 3) |

SET IDs: `SET_INT03_bare`, `SET_INT03_fitout`, `SET_INT03_finished`.

Interior light: daylight through the room’s windows as key (direction from the room’s position on the yacht) + warm practicals 2700K; night versions only for the hero / anchorage beats.

### Step 3 — Anchors

- **LOCATION ANCHOR:** 30–50 words · English · space type → size → surfaces → fixed equipment → key light → atmosphere. No yacht, no camera, no people.
- **SET ANCHOR:** 30–50 words for each set; same rules; must include the room’s window type and signature detail.

Wrong:

```
A huge epic shipyard at night with dramatic cinematic lights
```

Right:

```
Enclosed construction hall, 230 m long and 62 m high, grey epoxy floor, two yellow overhead gantry cranes on rails, scaffolding towers along both walls, sodium high-bay lights casting warm top-down light, daylight shafts through roof panels, faint welding haze.
```

### Step 4 — Reference images

Per location and per set: LOCATION / SET ANCHOR + “wide establishing view, no yacht (for locations), 2–3 small distant human silhouettes for scale (locations only), no text, no signage, no logos” + style + aspect. Interior sets: no people. Generate with Nano Banana 2 (drafts) and finalize with Nano Banana Pro.

### Output

```json
{
  "locations": [{"id": "LOC04", "used_in": ["B6", "B7", "B8"], "space": "…", "scale_check": {"fits": true}, "key_light": {"source": "sodium high-bay", "direction": "top-down", "kelvin": 2200}, "palette": ["#…"], "ambience": "hall hum, distant grinding", "anchor": "…", "ref_image": "LOC04_ref.png", "locks": ["crane color", "floor color"]}],
  "sets": [{"id": "SET_INT03_finished", "room": "INT03", "state": "finished", "anchor": "…", "key_light": {"source": "daylight through side glazing", "kelvin": 5600}, "ref_image": "…"}]
}
```

### Gates

- **RULE:** every beat has a location or set; anchors 30–50 words; Kelvin numeric; `scale_check.fits` true; no forbidden or blocklisted words.
- **VISION:** no text, no logos; locations show tiny human silhouettes for scale; sets show no people; finished sets match the room’s materials and palette (JUDGE with vision).

### Quality gate

- [ ] All exterior locations and all room sets in three states
- [ ] Scale fits 110–180 m
- [ ] One key light with Kelvin per location and set
- [ ] Reference images clean and consistent with anchors

## Phase 5L — Look test and style lock

Phase 5L renders a few representative shots in several styles and with each tool, scores them, and locks one style plus a preferred image and video tool per shot category. Spending \~40 images and a few clips here protects hundreds of later renders.

You are the director of photography responsible for the look. **Input:** reference pack (3R) · locations and sets (5) · {visual\_style} · tool profiles (0).

### Absolute rules

- No production keyframe (Phase 7) before the style is locked.
- Test shots cover four light conditions: hall, interior day, open sea, night.
- All variants use the same anchors, camera and references; only the STYLE sentence and the tool change.
- Decisions come from scores, not taste.

### Step 1 — Test shots

| ID | Content | Tests |
| --- | --- | --- |
| T1 | BS08 at the PROGRESS angle in LOC04 | raw metal, sodium light, scale |
| T2 | SET\_INT03\_finished, wide | materials, daylight interior |
| T3 | BS12 at sea, 3/4 bow, LOC07 | final paint, water, reflections |
| T4 | BS12 at blue hour, deck lights on, LOC08 | night look, lighting signature |

### Step 2 — Style candidates (English)

- **A — Natural documentary:** “photorealistic documentary photography, natural color, soft contrast, true-to-life materials”
- **B — Restrained cinema:** “cinematic photorealism, restrained contrast, slightly desaturated, subtle film grain, anamorphic feel”
- **C — Editorial bright:** “high-key editorial architecture photography, clean whites, crisp detail, minimal shadows”

Render each style × each test shot × two tools (Nano Banana Pro, GPT Image 2) = 24 images (+ 1 retry each if needed).

### Step 3 — Scoring

| Criterion | Gate | Weight |
| --- | --- | --- |
| Similarity to model sheet / room board | VISION | 30% |
| Consistency across the four tests in the same style | VISION | 25% |
| Aesthetic score | VISION | 15% |
| Fits “understated luxury”, fits the theme, no CGI look | JUDGE (vision) | 30% |

Discard any image with text or faces. Winning style = highest mean; ties < 3% → closest to {visual\_style}.

Also record which image tool won each category (exterior with yacht · interior · location without yacht · night).

### Step 4 — Motion test

- Animate T3 (exterior) and T2 (interior, slow push-in) with Veo 3.1 Fast and Omni 1.1 Flash, low and medium intensity: 8 short clips.
- Measure hull stability, flicker and motion smoothness (method in Phase 8).
- Record the preferred video tool per category and the maximum safe intensity.

### Step 5 — Locks

- `style.suffix` — winning STYLE sentence used in every Phase 7 prompt.
- `style.ref_images` — 2–3 best images, added to the reference pack.
- `style.palette` — 5 dominant colors measured from winners.
- `style.grade_target` — mean luma, white balance, saturation for color matching in 8P and 9.
- `style.seed_policy` — fixed seed per shot = hash(shot ID) where the tool exposes a seed; otherwise rely on references and record “no seed”.
- `routing.image{category: tool}` and `routing.video{category: tool}` — overrides the defaults in Phase 0.
- `motion.max_intensity{category}`.

### Output

```json
{
  "style": {"winner": "B", "suffix": "cinematic photorealism, …", "ref_images": ["T1_B_nbp.png", "T3_B_nbp.png"], "palette": ["#2B2E33"], "grade_target": {"luma_mean": 0.41, "wb_k": 5600, "sat": 0.9}, "seed_policy": "hash(shot_id)"},
  "routing": {"image": {"exterior_yacht": "nano-banana-pro", "interior": "gpt-image-2"}, "video": {"exterior": "veo-3.1", "interior_move": "omni-1.1-flash"}},
  "motion": {"max_intensity": {"exterior": "low", "interior": "medium"}}
}
```

### Quality gate

- [ ] Four test conditions rendered with both image tools
- [ ] Style chosen by score with reasons
- [ ] Tool preference recorded per category for image and video
- [ ] Grade target, seed policy and max intensity set

## Phase 6 — Shot list

Phase 6 breaks every beat into shots whose cuts land on the music grid, whose length fits the assigned video tool, and whose content executes the story layer (reveal level, obstacles, loops, money shots).

You are the director of photography. **Input:** `story` (3B) · `script` (4) · `timing` (4M) · `locations`, `sets` (5) · `routing`, `motion` (5L) · tool profiles (0).

### Absolute rules

- Every cut lands on a point of the cut grid (4M). Money shots start on accents.
- Shot length 2–10 s and within the assigned tool’s clip lengths (Veo 3.1: 4, 6 or 8 s; Omni 1.1 Flash: 3–10 s, extendable to 40 s). Render length = shot + 0.5–1 s handle, rounded up to an allowed clip length.
- One camera move and one subject action per shot.
- Every scene opens wide (or aerial); no two adjacent shots with the same size AND angle.
- People only as hands, backs, silhouettes; no readable faces; no readable text or screens.
- One PROGRESS angle reused in every hall shot from BS06 to BS11.
- 15–20% extra coverage shots (`coverage: true`): rendered as keyframes only unless needed.

### Vocabulary

**Moves:** static · slow push-in · slow pull-out · pan L/R · tilt U/D · orbit L/R · crane up/down · drone rise · drone descend · drone forward · drone pull-back · tracking alongside (chase boat or drone) · gimbal walk-through (interiors) · handheld drift (interiors, subtle).

**Sizes:** EWS · WS · MS · CU · ECU (macro) · AERIAL.

**Lenses:** 14–24 mm interiors and hall scale · 35 mm PROGRESS and natural perspective · 50 mm medium details · 85–200 mm compressed exterior profiles and chase-boat tracking · macro for materials, welds, paint, water.

### Step 1 — PROGRESS angle

Define once, exactly, e.g.:

```
3/4 bow view from port side, camera 1.7 m above the hall floor, 110 m from the bow, 35 mm lens, yacht fills 75% of frame width
```

### Step 2 — Render types

| Type | Meaning | Typical tool |
| --- | --- | --- |
| A | START frame only | Veo 3.1 or Omni (image-to-video) |
| B | START + END frame (state change or defined camera path) | Veo 3.1 first-and-last frame; Omni first-and-last frame |
| C | START = selected out-point frame of the previous shot | Omni with 3 s video reference of the previous clip |
| D | long continuous take > 8 s (interior walk-through, hero orbit) | Omni 1.1 Flash + extend (≤ 40 s), or Veo extend |

### Step 3 — Interior tour coverage

- Follow the walkable order from Phase 4; plan at least one type-D walk-through linking 2–3 rooms.
- Each room: one wide establishing, one signature-detail shot, optional transition move.
- For the “empty room” loop: the `finished` shot repeats the camera of the earlier `bare` shot exactly (match pair).

### Step 4 — Difficulty and fallback

| Level | Typical content | Required |
| --- | --- | --- |
| EASY | static or slow move, static yacht, no particles | — |
| MEDIUM | yacht moving slowly, sparks, smoke, light water, interior walk | fallback written |
| HARD | fast water and spray, crane with suspended load, close hand work, orbit around a moving yacht, long type-D take | fallback + coverage alternative |

### Shot description rule

Describe what the camera physically sees.

- Wrong: “Epic shot showing the power and beauty of construction.”
- Right: “WS, hull at BS07 centered under the gantry, welder kneeling at the keel line, sparks falling, crane moving left in the background.”

### Output (one row per shot)

| Field | Example |
| --- | --- |
| id | SC07\_SH02 |
| beat / act | B7 / A2 |
| tl\_in – tl\_out | 36.25 – 40.00 (grid) |
| render\_sec / tool | 4 / veo-3.1 |
| loc or set | LOC04 |
| bs / room state | BS07 |
| size · angle · lens | WS · eye level port · 24 mm |
| move · action | slow push-in · welder at keel line, sparks |
| card | STAGE 07 — STEEL |
| sfx | arc crackle, hall hum |
| transition out | cut on downbeat |
| render type | A |
| difficulty · fallback | MEDIUM · static + edit zoom |
| progress · reveal | N · H2 |
| story role · match pair | obstacle sign / — |
| characters | CREW |
| coverage | false |

Summary: shot count · total main-shot time = {video\_length} · counts by type, difficulty, tool · PROGRESS list · match pairs · budget estimate (images and clips).

v6: when the shot list is locked, Phase 0’s provisional budget is replaced by final caps computed from this plan (keyframes by type × variants × rounds; clips by tool and length, extensions, coverage, retries). Sequences of consecutive shots in the same location are also marked as `sequence_id` so that Phases 7 and 8 can check consistency across the whole sequence, not only per shot.

### Gates

- **RULE:** every `tl_in`/`tl_out` on the cut grid; total = {video\_length}; render length allowed for the tool; vocabulary only; scene opens wide; no adjacent duplicate size + angle; no H5 before the hero beat; no `finished` room before A4; every obstacle has sign and proof shots; coverage 15–20%; HARD shots have fallback and coverage alternative.
- **JUDGE:** physical descriptions, interior path walkable, money shots given room to breathe (≥ 8/10).

### Quality gate

- [ ] Cuts on music grid; lengths fit tools
- [ ] Exterior, construction, interior tour and sea coverage complete
- [ ] Reveal ladder and room states respected
- [ ] PROGRESS angle constant; match pairs defined
- [ ] Budget within Phase 0 caps

## Phase 6C — Strict prompt compilation and validation (new in v6)

Phase 6C turns every shot of the locked shot list into a **compiled shot spec**: the exact image prompt, motion prompt, references, tool parameters and the list of constraints to verify — each clause traceable to a locked source. It is the only place where design text enters a prompt. Phases 7 and 8 may render only frozen compiled specs.

Roles are separated: the AI designer is creative before the design lock; the compiler only assembles after it; code validators decide whether a spec may be rendered.

**Input:** canonical JSON and constraint registry (3) · anchors, build and surface states (3) · reference pack (3R) · locations and sets (5) · style and routing (5L) · shot list (6) · tool profiles (0).

### Absolute rules

- Inputs are locked revisions only; every compiled spec records the revisions and hashes it used.
- Anchors, state sentences, set anchors, camera and light sentences are inserted by code from their keys. An LLM may write only the free **action** slot (≤ 25 words) and must not mention shape, color or material.
- Every clause carries a source tag (`key@revision`).
- A P0 or P1 conflict produces `BLOCKED` with the reason; it is never retried silently.
- A spec is frozen with a content hash; Phases 7 and 8 refuse any spec that is not frozen.

### Step 1 — Resolve the shot

From the shot record, resolve: state group and blocks to use:

| Shot content | Identity block | State block | Context block |
| --- | --- | --- | --- |
| BS01–BS04 (design states: drawings, screens) | design-state sentence + first sentence of the GEOMETRY ANCHOR (as a drawing, never photoreal) | — | location anchor (studio, screen room) |
| BS05 (scale model) | BuildState BS05 `compiled_text` (tank model) | — | location anchor (towing tank) |
| BS06–BS08 (early build) | BuildState `compiled_text` of that state (STATE GEOMETRY from `present_parts` / `absent_parts`) | SURFACE STATE from `surfaces_by_part` | location anchor |
| BS09–BS10 (late build) | full GEOMETRY ANCHOR | SURFACE STATE from `surfaces_by_part` | location anchor |
| BS11–BS12 (finished) | full GEOMETRY ANCHOR | FINISHED SURFACE | location anchor |
| Interior | SET ANCHOR of the room and set state | — | — |
| Location only | — | — | location anchor |

In Step 2, constraints before their `from_state` get `not_yet_built`; constraints after their `until_state` get `not_applicable`.

Plus: camera sentence (template from size, angle, height, distance, lens; PROGRESS sentence verbatim), key light, style suffix, aspect, character anchors, reveal-hiding phrase for H2–H4.

### Step 2 — Select constraints to check

From the registry, keep constraints whose `visible_from` matches the shot’s size, angle and reveal level. Mark each as `must_show`, `may_be_hidden` (reveal H1–H3 or cropped) or `not_applicable`. Example: a CU of the bow cannot verify deck count, so `C-P0-001` is `not_applicable` there.

### Step 3 — Compose

- **Image prompt:** fixed order — identity block · state block · location / set · action · characters · camera · light · style · aspect · constraints line.
- **Motion prompt:** Phase 8 formula built from structured fields (move, direction, speed, subject motion, environment, pace) + keep-fixed line + audio line + constraints.

### Step 4 — Model adapter

Translate the spec to the assigned tool’s capabilities: reference images within the tool’s limit (priority: nearest MS views → detail sheets → color sheet → location / set → style); exclusions as a positive constraints line; prompt length budget (drop style first, then action detail, never identity or state blocks); mode selection (Veo frame mode vs reference mode cannot be mixed on some endpoints; Omni video reference for type C); aspect and resolution.

### Step 5 — Validate (RULE)

| Check | Fail when |
| --- | --- |
| State conflict | a BS06–BS10 prompt contains finish words (paint color names, “glazing tint”, “gloss”) or a BS11–BS12 prompt contains raw-state words (primer, scaffolding) |
| Identity coverage | a `must_show` P0 constraint is not expressed by the identity block |
| Contradiction | two clauses conflict (e.g. “no glass” and “dark glazing”) |
| Forbidden content | forbidden words, brands, text requests, faces |
| Limits | prompt length, reference count, clip length, mode compatibility |
| Reveal | H2–H4 shot without a hiding phrase; H5 before the hero beat |
| Traceability | any clause without a source tag |

**State-specific term lists (v6.1).** The state-conflict check uses one list per state and applies it **only to yacht clauses** (identity and state blocks). Location, action and character clauses are checked against the environment list instead, so a finished yacht on a cradle beside scaffolding is valid.

| State | Allowed in yacht clauses | Forbidden in yacht clauses |
| --- | --- | --- |
| BS06–BS07 | bare steel, welded, red-oxide primer, open deck | superstructure, glazing, glass, paint color names, gloss, finished |
| BS08 | raw aluminium, empty window openings | glazing, glass panels, paint color names, gloss |
| BS09 | glazing fitted, protective film, raw or primed surfaces | glazing tint, final paint color names, gloss |
| BS10 | grey filler, primer, masking tape, glazing under film | final paint color names, gloss, tinted glazing |
| BS11–BS12 | final colors, gloss, tinted glazing | primer, filler, raw steel, raw aluminium, protective film (on the yacht) |
| Environment (any state) | scaffolding, cradle, cranes, hall, dock, tools, workers | — (checked only against the location anchor and shot list) |

v6.2: the term lists apply only to **positive** mentions generated from `present_parts` and `surfaces_by_part`. Negated phrases generated from `absent_parts` (“no superstructure”, “no glass”) are always valid. The primary check is structural: every part mentioned positively in the identity and state blocks must be in the state’s `present_parts`; every part in `absent_parts` must appear only in negated form.

**Operational check:** the shot’s `operational_context` (speed, at anchor, features active) is compared with the canonical `operational_limits`; any violation sets `validation.operational = BLOCKED`.

### Step 6 — Semantic review (JUDGE)

v6.1: **every** compiled shot is reviewed for the P0 and P1 constraints it carries with status `must_show` or `may_be_hidden`: a judge reads the compiled prompt next to the canonical JSON and answers per constraint consistent / inconsistent / unclear. Any inconsistent P0 or P1 → `BLOCKED`. Unclear → `UNCERTAIN` (handled by the run-mode policy). Sampling (20% of shots) applies only to P2 constraints and creative quality. A constraint that is `not_applicable` or `not_yet_built` is never reported as a failure — only a constraint that should be visible and is described wrongly is.

### Step 7 — Freeze

Write the compiled spec with its content hash and a lock record. Any later change to a source key creates a new spec revision through the invalidation rules.

### Output

Complete example (valid against schema 5; the hash is an example value):

```json
{
  "shot_id": "SC08_SH01",
  "revision": 1,
  "hash": "sha256:9b2e4c7a1f0d3e5b8c6a2d4f1e3b5c7d9a0b2c4e6f8a1b3c5d7e9f0a2b4c6d8e",
  "state": "BS08",
  "sources": {"canonical": "rev1", "constraints": "rev1", "build_states": "rev1", "style": "rev1", "shot_list": "rev2"},
  "image_tool": "gemini-3-pro-image-preview",
  "image_mode": "edit_from_reference",
  "video_tool": "veo-3.1-generate-preview",
  "video_mode": "first_frame",
  "image_prompt": [
    {"block": "reference_instruction", "text": "Preserve the yacht geometry, silhouette and identity from the attached approved model sheet views.", "source": "templates.reference_instruction@1"},
    {"block": "identity", "text": "The hull with the superstructure recessed into the arch in four stepped terraces, window openings empty, no mast, no helideck surface.", "source": "build_states.BS08.compiled_text@1"},
    {"block": "state", "text": "Hull surfaces in bare welded steel with red-oxide primer; superstructure in raw unpainted aluminium; no paint, no glass.", "source": "surface_states.BS08@1"},
    {"block": "location", "text": "Enclosed construction hall, 230 m long and 62 m high, grey epoxy floor, two yellow overhead gantry cranes, scaffolding towers along both walls.", "source": "anchors.location.LOC04@1"},
    {"block": "action", "text": "A crane hook hangs above the bow; two workers seen from behind on the scaffolding.", "source": "shot_list.SC08_SH01.action@2"},
    {"block": "camera", "text": "3/4 bow view from port side, camera 1.7 m above the hall floor, 110 m from the bow, 35 mm lens.", "source": "progress_angle@1"},
    {"block": "light", "text": "Sodium high-bay light 2200K from above with daylight shafts 5600K.", "source": "locations.LOC04.key_light@1"},
    {"block": "style", "text": "cinematic photorealism, restrained contrast, slightly desaturated, subtle film grain", "source": "style.suffix@1"},
    {"block": "aspect", "text": "16:9", "source": "variables.aspect_ratio@1"},
    {"block": "constraints", "text": "No text, no lettering, no numbers, no logos, no signage, no visible faces, hull symmetric.", "source": "templates.constraints@1"}
  ],
  "motion_prompt": "Camera slow push-in toward the bow, very slow. The crane hook sways slightly. Keep fixed: hull shape, number of decks, scaffolding, hall. Audio: hall hum and distant grinding only, no music, no speech. No text.",
  "refs": ["MS2.png", "MS7.png", "detail_N1.png", "LOC04_ref.png"],
  "constraints_to_check": [
    {"id": "C-P0-001", "status": "must_show"},
    {"id": "C-P0-002", "status": "must_show"},
    {"id": "C-P1-007", "status": "may_be_hidden"},
    {"id": "C-P1-009", "status": "not_yet_built"}
  ],
  "validation": {"rule": "PASS", "judge": "PASS", "operational": "PASS"},
  "status": "FROZEN"
}
```

### Quality gate

- [ ] Every shot has a frozen compiled spec or a BLOCKED reason
- [ ] No state conflicts; every must-show P0 constraint expressed
- [ ] Every clause traceable to a locked source
- [ ] Tool limits respected
- [ ] UNCERTAIN items sent to the human queue

## Phase 7 — Keyframes

Phase 7 produces the START (and END) frames of every shot with GPT Image 2 or Nano Banana, using the approved model sheet and reference pack for geometry and identity, then scores every variant and keeps the best.

You are the AI image render operator. **Input (v6.3):** frozen CompiledShots (Phase 6C) and, for each call, a frozen RenderRequest built from them. Phase 7 never reads anchors, shot lists or style files directly; the prompt order below is reference documentation of what Phase 6C produces.

### Absolute rules

- English only. Anchors and state sentences pasted VERBATIM by code from their keys.
- One subject focus per frame.
- Never put text, numbers, signage, logos or brand names into the prompt.
- People always stated as “seen from behind”, “in silhouette” or “hands only”.
- No hype words: masterpiece, best quality, 8k, ultra detailed, award-winning, epic.
- Camera stated physically: size, height, distance, lens.
- Neither image tool has a dedicated negative-prompt field: exclusions are written as a positive CONSTRAINTS line at the end.

v6: Phase 7 renders **only from frozen compiled specs** (Phase 6C). It does not assemble prompts itself; the prompt order below documents what 6C produces. A shot without a frozen spec, or whose spec hash does not match, is refused.

### Prompt order (reference — produced by Phase 6C)

1. Reference instruction (yacht shots): preserve the approved yacht geometry, silhouette and identity from the attached model sheet views.
2. Identity block — GEOMETRY ANCHOR for BS06–BS10; YACHT ANCHOR (geometry + finished surface) for BS11–BS12; SET ANCHOR for interiors; omitted for BS01–BS05 and location-only shots
3. State block — build-state sentence + SURFACE STATE (BS06–BS10), or room state
4. LOCATION ANCHOR (exteriors)
5. Action — one physical action; CHARACTER ANCHOR if a character appears
6. Camera — size, angle, height, distance, lens (PROGRESS shots paste the PROGRESS sentence)
7. Light — key light line from Phase 5
8. Style — `style.suffix`
9. Aspect — {aspect\_ratio}
10. Constraints — “No text, no lettering, no numbers, no logos, no flags, no signage, no readable screens, no visible faces, no extra decks, no extra masts, hull symmetric, correct reflections.”

BS01–BS05: replace 1–2 with the BS sentence + “showing the profile of ” + the first sentence of the YACHT ANCHOR.

### Tool-specific handling

| Tool | Inputs | Notes |
| --- | --- | --- |
| Nano Banana Pro | approved reference image as edit input + up to 13 references from the reference budget (3R) | best for multi-reference consistency; 2K default, 4K for hero frames |
| Nano Banana 2 | same, fewer references | coverage, drafts, cheaper retries |
| GPT Image 2 | approved yacht reference or SET ref as edit input + 2–4 key references | strong instruction following; good for interiors and precise edits; up to about 2K |

Routing follows `routing.image` from 5L; the other tool is the automatic second attempt when all variants fail.

### Examples

Wrong:

```
Epic cinematic shot of a beautiful luxury superyacht being built in a huge shipyard, masterpiece, 8k
```

Right (exterior, BS08, LOC04):

```
Preserve the yacht geometry, silhouette and identity from the attached approved model sheet views. [GEOMETRY ANCHOR]. [SURFACE STATE BS08]. The yacht as a steel hull joined to raw unpainted aluminium superstructure, scaffolding along both sides. [LOCATION ANCHOR LOC04]. A yellow gantry crane hook hangs above the bow; two workers seen from behind on the scaffolding, plain burnt-orange coveralls. 3/4 bow view from port side, camera 1.7 m above the hall floor, 110 m from the bow, 35 mm lens. Sodium high-bay light 2200K from above with daylight shafts 5600K. [style.suffix]. 16:9. No text, no lettering, no numbers, no logos, no signage, no visible faces, hull symmetric.
```

Right (interior, finished):

```
Use the attached reference as the exact room layout and camera. [SET ANCHOR SET_INT03_finished]. Morning daylight 5600K through the full-height side glazing, warm 2700K practicals off. Wide view from the forward entrance, camera 1.4 m high, 18 mm lens, level horizon. Empty room, cushions crisp, one glass of water on the low travertine table. [style.suffix]. 16:9. No text, no logos, no books with titles, no screens with content, no people.
```

### Type B, C, D

- **B — END frame:** copy the START prompt; change ONLY the state sentence or the camera end position, never both; attach the START image as a reference.
- **C:** no prompt; START = frame at the previous shot’s selected out-point.
- **D:** START frame as type A; continuity comes from the video tool.

### Reveal control

For H2–H4, the camera or light line states how the yacht is hidden: “tight crop on the bow only”, “strong backlight, hull in silhouette”, “partly hidden behind scaffolding”, “haze over the stern”.

### Scoring and selection (3–4 variants per frame)

| Score | Measure | Start threshold | Weight |
| --- | --- | --- | --- |
| Text in image | OCR | any text → reject | — |
| Faces | face detection | face > 2% of frame → reject | — |
| Geometry defects | vision judge: extra decks, warped hull, asymmetry, bad hands | yes → reject | — |
| Model-sheet match | image embedding vs nearest MS view | ≥ 0.80 | 20% |
| Correct state | vision judge: build state / room state correct | yes, confidence ≥ 0.7 | 15% |
| Style | embedding vs `style.ref_images` | ≥ 0.75 | 10% |
| Location / set | embedding vs LOC or SET ref | ≥ 0.70 | 10% |
| Size and reveal | vision judge vs `size`, `reveal` | match | 15% |
| Aesthetic | aesthetic model | ≥ 5.5 / 10 | 10% |

- Normalize the remaining score weights by their sum, then pick the highest total among non-rejected variants.
- PROGRESS frames: hull bounding box within 5% of the previous PROGRESS frame.
- Type B END: similarity to START ≥ 0.85 except for the changed element.
- After 3 rounds: switch tool; then simplify (nearest MS angle, smaller size), flag `FRAME_SIMPLIFIED`; then use a coverage shot.
- Upscale the chosen frame to the video resolution (1080p or 4K).
- All thresholds are starting values; calibrate in pilot round 2.

### Constraint checks and sequence consistency (v6)

- **Per-constraint check:** for every constraint in the spec’s `constraints_to_check`, a vision judge answers a direct question (e.g. “How many decks are above the hull?”, “Are the hull doors at about mid-length?”). `must_show` P0 fails → reject; P1 fails → reject unless `may_be_hidden`; P2 fails → flag. An answer the judge cannot see → `UNCERTAIN`.
- **Landmark review:** compare visible identity landmarks with the approved model sheet and reference views. Missing or displaced identity features require review; do not claim a metric 3D measurement from uncalibrated images.
- **Sequence consistency:** after all frames of a `sequence_id` are selected, compare them as a set (hull color, deck count, light direction, location locks). One frame out of line → re-select from its variants before rendering video.
- **Verdicts:** every frame ends as `PASS`, `FAIL` or `UNCERTAIN`; UNCERTAIN frames go to the human review queue before Phase 7A.

### Output

```json
{
  "shot": "SC08_SH01",
  "frames": {"start": {"tool": "nano-banana-pro", "recipe": ["geometry", "anchors.yacht", "build_states.BS08", "anchors.location.LOC04", "action", "camera", "light", "style", "aspect", "constraints"],
    "refs": ["MS2.png", "MS7.png", "detail_N1.png"],
    "variants": [{"file": "SC08_SH01_START_r1a.png", "scores": {"ms": 0.83, "state": 0.9, "aesthetic": 6.1}}],
    "selected": "SC08_SH01_START.png"}}
}
```

### Gates

- **RULE:** prompt assembled from recipe in order; length within tool limit (drop style first, then action detail; never anchors); B END differs by one key; constraints line present.
- **VISION:** scoring table above.

### Quality gate

- [ ] Every A/B/D shot has a selected START; every B has an END
- [ ] Anchors verbatim (spot-check 3 by exact match)
- [ ] No text, logos or faces in selected frames
- [ ] PROGRESS frames aligned; interior finished frames match their bare counterparts where paired

## Phase 7A — Automatic animatic

Phase 7A lays the selected keyframes on the edited music, simulates camera moves, previews the hardest shots as cheap motion drafts, scores pacing and story clarity, fixes what can be fixed without new renders, and locks the pacing. Video budget is spent only after this lock.

You are the animatic editor. **Input:** keyframes (7) · `shots[]` with `tl_in`/`tl_out` · music edit and cut grid (4M) · `text_cards` · `reveal_plan`, `emotion_curve`.

### Absolute rules

- Built on the final edited music, never on temp music.
- Allowed edits: reorder, trim within the grid, swap to another variant or a coverage shot, move a card. New images → send that shot back to Phase 7.
- Max 2 automatic fix rounds.

### Step 1 — Build

- Place each START frame at `tl_in`–`tl_out` on the music.
- Simulate moves: push-in = scale 100 → 108%; pull-out reverse; pan = shift 5–8%; static = still; type B = dissolve START → END; type D = slow continuous scale + shift.
- Motion drafts (optional, recommended for HARD and type-D shots): render with Omni 1.1 Flash at 360p and drop them in place of the stills.
- Rough cards at their times; export 720p.

### Step 2 — Checks

| Gate | Check | Threshold |
| --- | --- | --- |
| RULE | every shot present; total = {video\_length}; every cut on the grid; money shots on accents; no gap > 0.3 s | all pass |
| RULE | actual reveal level per shot matches `reveal_plan`; no `finished` room before A4 | all pass |
| MEASURE | visual change events per 10 s window | ≥ 2 (short form), ≥ 1 (long form) |
| JUDGE | frame sequence + music section map + card list, rubric below | ≥ 8/10 |

| Animatic rubric | Points |
| --- | --- |
| First 3 s strong with sound off | 2 |
| Central question readable by \~10 s | 2 |
| Each obstacle shows sign then proof | 2 |
| Cuts and peaks feel driven by the music; no dragging section | 2 |
| Hero is the most beautiful moment and the first H5 | 2 |

### Step 3 — Automatic fixes

| Fix | Example |
| --- | --- |
| `reorder` | swap SC09\_SH02 and SC09\_SH03 |
| `trim` | shorten SC12\_SH01 by one bar, give the bar to the hero |
| `swap_variant` | use the second-best START of SC05\_SH02 |
| `swap_coverage` | replace SC10\_SH03 by a coverage macro |
| `rerender` | back to Phase 7 with a reason (last resort) |

Still below threshold after 2 rounds → flag `ANIMATIC_LOW` and stop for a human, even in FULL\_AUTO.

### Step 4 — Pacing lock

- Write final `tl_in`/`tl_out`; REVIEW\_KEY stops for human approval.
- Video render length per shot = shot length + handle, rounded up to an allowed clip length of the assigned tool.

### Output

```json
{
  "animatic": {"file": "animatic_v2.mp4", "round": 2, "drafts": ["SC14_SH03_draft360.mp4"]},
  "scores": {"rules": "pass", "change_density_min": 2, "judge": 8.5},
  "edits": [{"type": "trim", "shot": "SC12_SH01", "bars": -1}],
  "locked": true
}
```

### Quality gate

- [ ] Built on the final music; all cuts on grid
- [ ] Rules and change density pass; judge ≥ 8 or stopped for a human
- [ ] Fixes logged; re-render requests sent
- [ ] Pacing locked; render lengths computed

## Phase 8 — Video shots

Phase 8 animates every shot with Veo 3.1 or Gemini Omni 1.1 Flash, using the locked keyframes, then scores each clip frame by frame and keeps the best segment.

You are the AI video motion director. **Input:** `shots[]` and keyframes (6, 7) · locked pacing (7A) · `routing.video`, `motion.max_intensity` (5L) · tool profiles (0).

### Absolute rules

- English only. Describe PHYSICAL motion only — never emotion, story or quality.
- One camera move per clip, from the Phase 6 vocabulary, with direction AND speed.
- The yacht never changes shape; the hull is rigid.
- Intensity ≤ `motion.max_intensity` for the category.
- Clip length = an allowed length for the tool (Veo 4 / 6 / 8 s; Omni 3–10 s; extensions for type D).
- Every prompt asks for ambient sound only: “Audio: natural ambient sound only, no music, no speech.”
- No text may appear; no faces.

v6: motion prompts, references and tool parameters come from the frozen compiled spec (Phase 6C); Phase 8 does not reinterpret the design. Clip verdicts are `PASS`, `FAIL` or `UNCERTAIN`; UNCERTAIN clips go to the human queue. After all clips of a `sequence_id` pass, the sequence is played back-to-back and checked once more for hull color, light direction and continuity across cuts.

v6.2: a motion prompt may not describe a speed, sea state or feature state beyond the canonical `operational_limits` for that shot’s `operational_context` (checked in Phase 6C before freezing).

### Tool modes

| Tool | Mode | Use for |
| --- | --- | --- |
| Veo 3.1 Quality | first frame (A) or first-and-last frame (B) | exterior heroes, sea trials, aerials, launch, final-quality water |
| Veo 3.1 Fast | same | most hall and exterior shots |
| Veo 3.1 Lite | same | coverage and retries |
| Veo 3.1 reference mode | 1–3 reference images, fixed 8 s on some endpoints | shots without a usable START frame; not combined with frame mode |
| Veo 3.1 extend | continues a clip | rare long exterior takes |
| Omni 1.1 Flash | first frame / first-and-last frame + image references | interiors, continuity-heavy shots |
| Omni 1.1 Flash + video reference | up to 3 s of the previous selected clip | type C continuity |
| Omni 1.1 Flash extend | 3–10 s hops, up to 40 s total, 10 s of context | type D walk-throughs, long hero orbits |
| Omni 1.1 Flash edit | instruction on an existing clip | local fixes (Phase 8P) |
| Omni 1.1 Flash 360p draft → upscale | cheap draft, then 1080p / 4K | animatic drafts; final after approval |

### Motion prompt formula

&#91;CAMERA: move + direction + speed\] + \[SUBJECT: what moves and how\] + \[ENVIRONMENT: water, air, particles\] + \[PACE\] + \[KEEP FIXED\] + \[AUDIO\] + \[CONSTRAINTS\]

Wrong:

```
Epic cinematic reveal of the superyacht, dramatic and powerful, amazing
```

Right (exterior, Veo 3.1, type A):

```
Drone rises slowly from 3 m to 45 m above the water while tilting down. The yacht holds a straight course at steady cruising speed. Smooth bow wave, long flat white wake, light swell, spray drifting left. Calm, steady pace. Keep fixed: hull shape, number of decks, colors, window layout, horizon level. Audio: natural ambient sound only, no music, no speech. No text, no logos, no people visible.
```

Right (interior walk-through, Omni 1.1 Flash, type D):

```
Gimbal walk-through at slow walking pace from the main lobby into the main salon, moving straight forward, camera height 1.4 m. Sheer curtains move slightly in a breeze, sunlight patterns drift across the oak floor, the sea moves outside the windows. Keep fixed: room layout, materials, furniture positions, window shapes. Audio: soft room tone and distant sea, no music, no speech. No people, no text, no screens with content.
```

Right (build-state morph, Veo 3.1 first-and-last frame, type B):

```
Camera static. Grey primer spreads across the hull from bow to stern while masking tape lines appear along the edges; scaffolding stays in place. Steady pace. Keep fixed: camera, hull silhouette, hall, lighting. Audio: spray-gun hiss and hall hum only. No text.
```

### Motion physics

- Displacement yacht: smooth bow wave, long flat wake; never jumps, never planes.
- Hull rigid: no bending, flexing or rippling surfaces; reflections follow the hull.
- Crane lifts: slow, constant speed; load sways < 1 m.
- Sparks fall and fade; smoke rises slowly; paint mist drifts softly.
- Interiors: water in a glass stays still unless the shot is the vibration obstacle; curtains move gently; no furniture moves.
- Hull doors and terraces move slowly and mechanically, never morph.

### Intensity by category

| Category | Intensity | Motion |
| --- | --- | --- |
| Sketch / screen (BS01–BS04) | low | line draws itself, wireframe rotates slowly |
| Tank (BS05) | low | model moves forward, small bow wave |
| Hall (BS06–BS11) | low | camera + particles; yacht static |
| Launch (BS12) | medium | yacht descends or floats off slowly, water displaces outward |
| Sea trials | medium | yacht moving, camera tracking |
| Interiors | low–medium | camera move only; ambient life (curtains, light, sea outside) |
| Hero | low | slow drone move, near-still yacht |

### Scoring and segment selection

Sample one frame every 0.25 s; use an image-derived yacht mask when available; otherwise inspect the full frame.

| Score | Measure | Start threshold |
| --- | --- | --- |
| Hull stability | embedding similarity of the hull crop vs the first frame | no frame < 0.90 |
| Room stability (interiors) | similarity of fixed elements vs first frame | no frame < 0.88 |
| Flicker | mean-luma variance between consecutive frames | ≤ calibrated threshold |
| Smooth motion | optical-flow magnitude without spikes | no spike > 3× mean |
| Unwanted cuts | scene-cut detection | 0 |
| Frozen frames | repeated-frame detection | none > 0.3 s |
| Text / faces | OCR and face detection on samples | 0 |

- Slide a window of the shot length; keep the best window with no frame below threshold; store `in`, `out`.
- Type C: the next shot’s START comes from the selected `out` frame; its video reference is the last 3 s before `out`.

### Attempt ladder

1. Routed tool at the profile intensity.
2. Lower intensity, remove environment motion.
3. Switch to the other video tool.
4. Fallback: local defect → Omni edit (8P); usable 2–3 s → shorten; still + motion in edit; swap to a coverage shot.

### Output

```json
{
  "shot": "SC13_SH02",
  "clip": {"tool": "veo-3.1-quality", "mode": "first_frame", "input": "SC13_SH02_START.png", "length": 8, "intensity": "medium",
    "motion_prompt": "…", "attempts": [{"file": "SC13_SH02_r1.mp4", "scores": {"hull": 0.93, "flicker": 0.012}}],
    "selected": "SC13_SH02_r1.mp4", "in": 0.75, "out": 6.0, "fallback_level": 0}
}
```

### Gates

- **RULE:** one camera move from the vocabulary with direction and speed; no emotion or quality words; audio line and keep-fixed line present; length allowed for the tool; intensity within limit; ≤ 3 attempts before fallback.
- **VISION:** scoring table above.

### Quality gate

- [ ] Every main shot has a selected clip or a logged fallback
- [ ] No clip with hull deformation, text or faces in the used segment
- [ ] Type C and D continuity verified
- [ ] Costs within budget

## Phase 8P — Clip post-processing

Every selected clip passes the same fixed chain so that clips from two video models and many renders look like one camera. Each clip is processed as soon as it passes Phase 8.

You are the image post technician. **Input:** selected clip with `in`/`out` · `style.grade_target` (5L) · export specs.

### Absolute rules

- Never overwrite the source; every step writes a new file.
- Fixed order; skip steps that are not needed, never reorder.
- A step runs only when a measurement says it is needed.
- No step may change the yacht’s shape.
- Re-score after processing; a step that lowers a score is dropped.

### Chain

| # | Step | Runs when | Tool example | Limit |
| --- | --- | --- | --- | --- |
| 1 | Trim to `in`/`out` + 6–12 frame handles | always | ffmpeg | — |
| 2 | Normalize fps, resolution, color space (Rec.709) | always | ffmpeg | project fps 24 or 30 |
| 3 | Strip or keep native audio | always | ffmpeg | keep as separate ambient stem; never mixed raw |
| 4 | Local fix by instruction | small defect: stray text, extra object, short warp | Omni 1.1 Flash edit | then re-score; failure → inpaint or Phase 8 |
| 5 | Inpaint / blur region | defect < 3% of frame and < 0.5 s | inpainting tool | larger → back to Phase 8 |
| 6 | Deflicker | flicker above threshold | ffmpeg deflicker | do not flatten intentional sparks |
| 7 | Stabilize | unintended shake | vidstab | never on handheld drift shots |
| 8 | Frame interpolation | source fps below target, or planned slow-motion on money shots | RIFE or minterpolate | max 2× |
| 9 | Upscale | below target resolution | Omni / Veo 4K upscale or Real-ESRGAN | check hull edges for over-sharpening |
| 10 | Primary color match | always | match to `style.grade_target` per location / set | primary only; act looks come in Phase 9 |
| 11 | Composite (optional) | shot list asks for haze, spray, sparks overlay | licensed overlay library | never cover > 10% of the hull |

- Clips in the same location or set match mean luma and white balance within 5%.
- BS11–BS12 clips: hull color measured against the HEX values; correct that color range only.

### Re-score

| Score | Requirement |
| --- | --- |
| Hull / room stability | ≥ value before processing |
| Flicker | ≤ threshold |
| Text / faces | 0 |
| Edge sharpness | ≤ +20% vs before upscale |
| Color deviation vs location / set | ≤ 5% |

### Output

```json
{
  "shot": "SC14_SH03",
  "post": {"source": "SC14_SH03_r2.mp4", "output": "SC14_SH03_post.mov",
    "ops": [{"step": 4, "op": "omni_edit", "instruction": "remove the small dark object on the left wall, keep everything else unchanged"}, {"step": 10, "op": "color_match", "target": "SET_INT03_finished"}],
    "ambient_stem": "SC14_SH03_amb.wav",
    "scores_after": {"room": 0.94, "flicker": 0.01, "ocr": 0, "faces": 0}}
}
```

### Quality gate

- [ ] Sources kept; every step logged with its reason
- [ ] Scores after ≥ before
- [ ] Uniform fps, resolution and color space
- [ ] Same-place clips color-matched; ambient stems saved

## Phase 9 — Assembly and mix (music-driven)

Phase 9 builds the film as data from the manifest: clips on the music grid, transitions, cards, act looks, sound design and loudness, then exports the master, the platform versions and the publishing assets.

You are the editor and post supervisor. **Input:** processed clips (8P) · music edit, cut grid, accents (4M) · `text_cards`, `sfx_plan` (4) · channel bible · pacing lock (7A).

### Absolute rules

- The music is the master clock: picture cuts follow the grid; the music is never stretched to fit picture.
- Cut on motion: end a shot while something moves; start the next mid-motion.
- Dissolves only for time jumps and the PROGRESS timelapse.
- The timeline is generated as data (e.g. OpenTimelineIO) from the manifest; no manual assembly.
- No voice, no lyrics anywhere in the mix.
- The disclaimer appears on screen and in the description.

### Timeline

| Track | Content |
| --- | --- |
| V1 | clips at `tl_in`–`tl_out` |
| V2 | text cards, CONCEPT labels, channel mark, disclaimer, end card |
| A1 | edited music (or stems) |
| A2 | SFX from {sfx\_library}, per shot |
| A3 | ambience: location beds + selected native ambient stems from the video models |
| A4 | sonic logo |

### Transitions

| Type | How | Limit |
| --- | --- | --- |
| cut | straight cut on a grid point | default |
| match cut | align shape positions using image-derived masks or approved reference landmarks; shift / scale the second frame if offset > 5% | declared `match_pair` only |
| dissolve | 6–12 frames | time jumps and timelapse only |
| morph (PROGRESS timelapse) | align hull bounding box by mask, dissolve 6–12 frames, hold each state ≥ 1 bar | BS06 → BS11 |
| whip pan | motion blur 4–6 frames, on an accent | max 2 per film |

### Retention editing

- First 3 s: strongest motion or contrast image and a sharp sound; never open on black or a slow fade.
- No dead stretch: a clear change of image, card or sound at least every 5 s (short form) or 8 s (long form).
- Speed ramps only on money shots, max 2.
- Short form: last frame loops back to the first frame for rewatch.

### Text cards and graphics

- Rendered from the channel-bible type system; in/out 6–8 frames; safe zones: 9:16 avoid top \~15% and bottom \~20%; 16:9 keep a 5% margin.
- Spec cards always carry “· CONCEPT”; disclaimer and end card ≥ 3 s.
- Channel mark after the hook (≈ 3–5 s) or small in a corner.

### Color by act

On top of 8P primary matching: A1 cool neutral, clean whites · A2 warm industrial, sodium amber highlights · A3 rising contrast, clean white paint light · A4 natural, deep sea blue; interiors warm daylight. Re-check hull HEX after the look in BS11–BS12 shots.

### Sound design and mix

- SFX placed per shot from the library by tags (graphite, arc welding, crane chain, air tools, spray gun, film peel, water rush, engines, wind, room tone); close-detail sounds slightly larger than life; sub-bass hit for the superstructure landing and the first contact with water.
- Ambience: one bed per location, crossfade 0.5–1 s on location change; native ambient stems kept low under the music.
- J-cut at every act change: next act’s sound leads picture by 6–12 frames.
- Silences as planned: music drop before B8 and sparse music for B16; let natural sound carry.
- Loudness: two-pass normalization to about −14 LUFS integrated (adjust per {platform}); true peak ≤ −1 dBTP.

### Export

- Master first (high-quality intermediate), then platform versions: resolution, fps, codec, bitrate per {platform}.
- 16:9 → 1920×1080 or 3840×2160; 9:16 → 1080×1920; H.264 or H.265.

### Publishing assets

- **Thumbnail:** 2 English image prompts (YACHT ANCHOR + hero composition + high contrast + clear space for text), rendered with the routed image tool; 2 text options ≤ 4 words containing a name or a number.
  - Wrong: “YOU WON’T BELIEVE THIS”. Right: “148 M. IT OPENS.”
- **Title:** 60–90 characters; starts with a name, number or active verb (never How / Why / The / Watch); contains “concept”.
  - Right: “Noctiluca: a 148 m concept superyacht that opens its hull at sea”
- **Description:** line 1 what the viewer sees (concept, studio); line 2 “Design inspired by current superyacht design trends” (optional credit to the article as inspiration); line 3 disclaimer; music credits as required by the license.
- **FB\_IMAGE\_TEXT:** one sentence, 70–100 characters, strong active verb, must include the yacht’s name; hook + tension; no emoji.
  - Right: “Noctiluca opens its 148 m hull at sea — and the steel had to bend first.”
- **FB\_POST\_CONTENT:** 150–250 characters, plain text, no emoji, URL, hashtags or bullets; formula: named yacht + specific design stake + build tension + withheld outcome; no explicit CTA.
  - Right: “Noctiluca was drawn with doors in its hull. Eight decks, one weather window and a single high tide decide whether the idea survives the sea.”

### Output

```json
{
  "timeline": "final.otio",
  "master": "master.mov",
  "exports": [{"platform": "YouTube", "file": "yt_3840x2160.mp4"}],
  "mix": {"lufs": -14.0, "true_peak": -1.2},
  "transitions": [{"pair": ["SC02_SH01", "SC16_SH03"], "type": "match_cut"}],
  "post_assets": {"title": "…", "description": "…", "fb_image_text": "…", "fb_post": "…", "thumbnails": ["…"], "ai_label": true}
}
```

### Gates

- **RULE:** total = {video\_length}; every cut on the grid; every shot ID exists; no gaps; cards inside safe zones, ≥ 1.5 s; title 60–90 characters with “concept”; FB image text 70–100 with the name; FB post 150–250 without emoji / URL / hashtags; disclaimer in end card and description; music credits present if required.
- **MEASURE:** loudness and true peak; change events per window.
- **JUDGE:** title and posts follow the formulas, carry tension, withhold the outcome.

### Quality gate

- [ ] Timeline generated from manifest; music untouched by picture
- [ ] Transitions within limits; timelapse aligned
- [ ] Mix at target loudness; silences where planned
- [ ] Master and platform exports done
- [ ] Publishing assets compliant

## Phase 9H — Opening lab

The first 3 seconds decide most of the views, so the opening is tested in several versions instead of betting on one.

You are the opening optimizer. **Input:** the Phase 9 cut · `shots[]` incl. coverage · `money_shots` · `reveal_plan` · cut grid.

### Absolute rules

- Built from existing assets; at most one new render for the whole phase.
- No variant shows H5 or answers the central question.
- Every variant keeps the music unchanged (only picture and optional card change).
- Every variant passes Phase 9Q.

### Steps

1. Build 2–3 variants of 0–5 s, differing by hook type (A–F) or flash-forward image.
2. Score:

| Criterion | Gate | Points |
| --- | --- | --- |
| First frame has strong motion or contrast | VISION / JUDGE | 3 |
| Concrete anchor: shape, scale cue, name or number | RULE / JUDGE | 2 |
| Raises a question the viewer wants answered | JUDGE | 3 |
| First cut lands on a strong beat | RULE | 1 |
| ≥ 2 visual changes in the first 5 s | MEASURE | 1 |

3. The best variant goes into the main cut. Where the platform supports experiments, publish variants as a test; otherwise rotate hook types across videos and feed results to Phase 10.

**Keys:** `openings[]{id, hook_type, shots[], card, score, selected}`.

## Phase 9Q — Final QC

Phase 9Q checks the exported film in seven groups and returns PASS, PASS WITH FLAGS or FAIL, routing each defect back to the phase that caused it.

You are the final inspector. **Input:** master and platform exports (9, 9H) · full manifest.

### Absolute rules

- Check the exported files, not the timeline.
- Every defect has a location: timecode, shot ID or field.
- Nothing is published while the Content & legal group fails — also in FULL\_AUTO.

### Group 1 — File

| Check | Measure | Threshold |
| --- | --- | --- |
| Resolution, fps, codec, bitrate | ffprobe | per {platform}; constant fps |
| Duration | ffprobe | {video\_length} ± 0.1 s |
| Audio format | ffprobe | 48 kHz stereo |
| Sync | music onset vs timeline | ≤ 1 frame |

### Group 2 — Image

| Check | Measure | Threshold |
| --- | --- | --- |
| Black frames | blackdetect | none > 0.1 s unplanned |
| Frozen frames | freezedetect | none > 0.5 s unplanned |
| Stray text | OCR every 0.5 s, excluding valid card regions and times | 0 |
| Faces | face detection every 0.5 s | 0 |
| Yacht consistency | hull crops in BS11–BS12 shots vs model sheet | ≥ Phase 7 threshold |
| Room consistency | finished-room shots vs room boards | ≥ threshold |
| Flicker | luma variance over the film | ≤ 8P threshold |

### Group 3 — Audio

| Check | Measure | Threshold |
| --- | --- | --- |
| Loudness and peak | loudness meter | ≈ −14 LUFS per {platform}; true peak ≤ −1 dBTP |
| Clipping | detector | 0 |
| No voice | speech / vocal detection on the final mix (incl. native stems) | none |
| Unplanned silence | silence detection | none > 1.5 s outside planned breaks |
| Music edits | click / discontinuity detection at edit points | none |

### Group 4 — Content and legal

- No fact, name or date from the article on screen (OCR vs `inspiration` terms).
- No real shipyard, designer, brand or famous yacht name in picture or metadata (blocklist).
- Every spec card ends with “· CONCEPT”; numbers match `yacht_bible`.
- Disclaimer on screen and in the description; AI-content label on.
- License evidence for music, SFX, fonts; music credits in the description if required.

### Group 5 — Story

Judge receives frame samples, card list and the music section map; animatic rubric (10 pts) + 2 pts: final cut no slower than the animatic (1) · sound design lifts the peaks (1). Pass ≥ 9/12.

### Group 6 — Platform

Cards inside safe zones; thumbnail OCR ≤ 4 words, no faces, no stray text; title and posts within limits.

### Group 7 — Channel brand

Type system, colors, channel mark, sonic logo and end card match the channel bible.

### Group 8 — Design fidelity and claims (v6)

- Every P0 constraint verified in at least one BS11–BS12 shot of the final cut; landmark overlay on 3 sampled hero frames.
- Sequence consistency across every `sequence_id` in the final cut.
- No “world-first”, “never seen before”, “first ever” or similar claims in cards, title, description or posts; the allowed claim is “original concept design”.
- An open P0 or P1 item in the review queue that affects the final cut blocks publishing (run state AWAITING\_REVIEW); P2 items accepted with flag are listed in the QC report and do not block.

A failure in Group 8 counts like a Group 4 failure: verdict FAIL, no publishing.

### Group 9 — Production value (v6.2)

Checklist in the section “Studio production value”. Failures here are P2 flags, except a missing disclosure line or AI speech in the mix, which count as Group 4 failures.

### Verdict and routing

| Verdict | Condition | Action |
| --- | --- | --- |
| PASS | all groups pass | Phase 10 (REVIEW\_KEY: final human approval) |
| PASS WITH FLAGS | minor issues in groups 2, 3, 6, 7 only | flag and continue |
| FAIL | any issue in groups 1 or 4, or story < 9/12 | route defects, one automatic fix round, then stop for a human |

| Defect | Route to |
| --- | --- |
| Stray text / face in a shot | 8P (Omni edit or inpaint) → else 8 |
| Yacht or room inconsistency | 7 → 8 → 8P for that shot |
| Loudness, clipping, unplanned silence | 9 (remix) |
| Voice detected | 8P (strip native stem) → 9 |
| Article fact or real name on screen | 4 (cards) → 9 |
| Dragging section | 9 (trim within available clip) |
| Brand mismatch | 9 (re-apply templates) |

### Output

```json
{
  "qc": {"verdict": "PASS_WITH_FLAGS",
    "groups": {"file": "pass", "image": "pass", "audio": "flag", "content": "pass", "story": 10, "platform": "pass", "brand": "pass"},
    "issues": [{"group": "audio", "t": 63.5, "issue": "unplanned_silence_1.7s", "route": "9"}],
    "report": "qc_report.md"}
}
```

### Quality gate

- [ ] Seven groups checked; every defect located
- [ ] Content & legal passed before publishing
- [ ] Defects routed; at most one automatic fix round
- [ ] Human-readable QC report produced

## Phase 10 — Release, cutdowns, localization, analytics

Phase 10 derives every release version from the same production, packages the posts, and measures performance to tune the next film.

You are the release and analytics unit. **Input:** master and timeline (9) · QC report (9Q) · `post_assets` · `openings[]` · platform config · {languages}.

### Absolute rules

- Every derived version is re-edited from existing clips and music; no new AI renders.
- Every derived version re-runs 9Q groups 1, 2, 4, 6 before publishing.
- Teasers and cutdowns never answer the central question and never use H5.
- Disclaimer and AI label on every version.

### Step 1 — Reframe

- 16:9 ↔ 9:16 by cropping around the yacht detected in the rendered frames with smoothed crop motion; interiors crop around the room’s signature element.
- Crop keeps ≥ 80% of the hull; otherwise pan-and-scan along the hull; otherwise center the frame over a blurred fill of the same shot.
- Re-place cards inside the new safe zones.

### Step 2 — Teaser and cutdown

| Version | Length | Recipe |
| --- | --- | --- |
| Teaser | 15 s | hook → 2 best money shots (≤ H4) → question card → end card; music cut at a phrase boundary |
| Cutdown | 30 s | hook → obstacle 2 (sign + proof) → launch → one interior money shot → open question → end card |
| Interior tour | 20–40 s (optional) | the room-by-room sequence with its own music phrase |

### Step 3 — Localization

With no voiceover, localization is light:

- Translate text cards, titles, descriptions and posts into {languages}; keep numbers, the yacht’s name and “CONCEPT” unchanged.
- Card length per language must still fit the card duration (≤ 6 words where possible); otherwise shorten, judge checks meaning.
- End card and disclaimer come from the channel bible’s pre-translated set.
- Re-render only the card layer; picture and music unchanged.

### Step 4 — Release package

- Title, description, FB image text, FB post per Phase 9; hashtags only where the platform config allows (none on FB posts).
- Two thumbnails; enable thumbnail experiments where the platform supports them.
- Publish time per channel config; AI label on; disclaimer and music credits checked.

### Step 5 — Metrics (at 48 h and 7 days)

| Metric | Used to judge |
| --- | --- |
| Views, thumbnail click-through | thumbnail and title |
| Average view duration, % viewed | overall film |
| Retention curve per second | where viewers leave |
| Rewatch rate (short form) | loop ending |
| Shares, comments | central question and posts |

### Step 6 — Drop-off analysis

- Map every retention drop (> 5% within 2 s) to its shot and beat on the timeline.
- Label: weak hook · dragging section · confusing obstacle · early reveal · weak interior pacing · ending.

### Step 7 — Feedback loop

- Store a feature record per film: hook type, story type, flash-forward, length, music BPM and genre, number of money shots, rooms in tour, style winner, opening variant, and the metrics.
- After ≥ 10 films, compare retention across feature groups.
- Change defaults only for clear, consistent differences; one variable at a time; every change goes through a prompt-version bump and the regression suite.

### Output

```json
{
  "releases": [{"type": "main", "aspect": "16:9", "file": "…"}, {"type": "teaser", "sec": 15, "file": "…"}],
  "localizations": [{"lang": "vi", "cards": "pass", "file": "…"}],
  "published": [{"platform": "YouTube", "time": "2026-10-21T19:00:00+07:00", "ai_label": true}],
  "analytics": {"d7": {"avg_view_pct": 0.0}, "drops": [{"t": 24.5, "shot": "SC05_SH02", "type": "dragging"}]},
  "features": {"hook_type": "E", "story_type": "A", "bpm": 96, "rooms": 7}
}
```

### Quality gate

- [ ] All derived versions built from existing assets and re-checked
- [ ] No answer and no H5 in teaser or cutdown
- [ ] Localized cards keep numbers, name and CONCEPT
- [ ] Metrics scheduled; feature record stored

## Studio production value (v6.2)

The goal is a film that feels made by a real concept-design studio: careful camera work, real textures, real sound and a recognisable studio identity. This raises quality only; the AI disclosure label and the “Concept design — not a real vessel” line stay on every release.

### Camera realism

- **Inertia:** every move eases in and out (no instant start or stop); drone moves accelerate over ≥ 1 s; handheld drift only indoors, amplitude < 1% of frame.
- **Physically possible paths:** no camera passing through walls, glass or hulls; drone heights and distances consistent with the scene scale; chase-boat shots at plausible offsets (30–150 m).
- **Lens consistency:** one lens family per film — consistent focal lengths per shot type (Phase 6), subtle vignetting, faint chromatic aberration at frame edges, realistic depth of field for the lens and distance.
- **Exposure behaviour:** highlights roll off softly; interior windows may clip slightly as a real camera would; no perfectly even light everywhere.

### Image texture

- One grain profile for the whole film (added in Phase 9, not per clip), matched to the grade.
- Mild, consistent sharpening only; reject clips with waxy surfaces or over-smooth water.
- Natural imperfection in the scene: dust in hall light shafts, water spots on glass at sea, wear on yard equipment — never on the finished yacht itself.

### Hybrid live footage

The shot list field `source` (ShotSpec) marks each shot as `ai`, `live` or `stock`:

| Source | Typical shots |
| --- | --- |
| `live` (shot by the studio) | the designer’s hands sketching, material swatches on a table, a scale ruler, a pencil line in macro, a glass of water on a table |
| `stock` (licensed) | open-sea plates, wake and spray textures, sky timelapses, harbour ambience at night |
| `ai` | everything showing the yacht, the yard and the interiors |

Live shots use the same grade target, grain and aspect as the AI shots; they follow the same reveal ladder and never show the yacht.

### Sound like a real production

- Record studio foley where possible: pencil on paper, page turns, ruler, fabric, footsteps on oak and teak, glass on stone.
- Layer licensed SFX for yard and sea; keep room tone continuous under every interior cut.
- Final mix in Phase 9 as specified; no AI-generated speech anywhere.

### Studio identity

- A consistent studio name, logo, typography and end card on every film (channel bible).
- A naming system for concepts and a numbered series (e.g. “Concept 04 — Noctiluca”).
- Description template: “Original concept design by {studio\_name}. Not a real vessel. Made with AI-assisted visualisation and live-action elements.”
- Optional companion videos: design process, material studies, behind the scenes of the studio.

### Gate (Phase 9Q, Group 9 — production value)

- [ ] Camera moves ease in and out; no impossible camera paths
- [ ] One grain profile and lens character across the film
- [ ] Live and stock shots graded to match; none shows the yacht
- [ ] Foley and room tone continuous; no AI speech
- [ ] Studio identity and disclosure line present

## Technical architecture

Laravel orchestrates; a Python worker handles media analysis and scoring; Blender and ffmpeg run as command-line tools; the image and video models are called as APIs.

| Component | Role | Suggested tech |
| --- | --- | --- |
| Orchestrator | DAG of phases, queues, retries, budgets, manifest, state | Laravel 13 (Queues, Job chains / batches, Horizon, Scheduler) |
| Review UI | REVIEW\_KEY stops: model sheet, animatic, final | Laravel + Livewire or Filament |
| Model calls | LLM, GPT Image 2, Nano Banana (Gemini API), Veo 3.1 and Omni 1.1 Flash (Gemini API / Vertex AI) | Laravel HTTP client; async jobs with polling for video |
| RULE gates | regex, counts, sums, JSON Schema validation | PHP |
| Python worker | vision scoring (embeddings, OCR, faces, optical flow, aesthetics), music analysis (beats, sections, energy, vocals), timeline build | Python + FastAPI; GPU host if models run locally |
| Media | trim, normalize, deflicker, stabilize, loudness, black / freeze detection, export | ffmpeg |
| Storage | images, clips, audio, masters, manifest | S3-compatible object storage (S3 or MinIO); services exchange paths only |
| Link Laravel ↔ worker | jobs and results | HTTP or a shared Redis queue |

**Video calls are slow:** Veo and Omni renders can take minutes each; run them as async jobs, poll for completion, and parallelize up to the account rate limits.

**Server sizing (reference):** Laravel + ffmpeg on about 4 vCPU / 8 GB RAM, no GPU. The Python worker on a separate GPU host if vision and music models run locally; without a GPU, use hosted APIs for scoring at a per-call cost.

### v6 decisions on components

| Component | v6 decision |
| --- | --- |
| Python worker | kept, full scope: vision scoring and per-constraint checks, clip analysis, music analysis, landmark overlays, timeline build |
| Human review UI | Laravel + Filament or Livewire: design lock (model sheet + canonical overlay), UNCERTAIN queue, animatic approval, final approval; side-by-side variants, keyboard approve / reject |
| Prompt compilation | Phase 6C service in Laravel: template assembly, model adapters, RULE validation; LLM only for the action slot and the semantic judge |

### Lock and artifact records (database)

| Table | Key fields |
| --- | --- |
| `artifacts` | id, project, type, revision, file path, content hash, source revisions, status (state-machine enum), fallback\_level |
| `locks` | id, project, lock type (design / timing / spec / pacing / publish), components (artifact id, type, revision, hash), bundle\_hash, approver, approved\_at — as LockRecord (schema 6) |
| `constraints` | id, priority, statement, check, visible\_from, canonical revision |
| `compiled_shots` | shot id, revision, hash, sources, image\_tool, image\_mode, video\_tool, video\_mode, image\_prompt, motion\_prompt, refs, constraints\_to\_check, validation, status — as CompiledShot (schema 5) |
| `review_queue` | item, reason (UNCERTAIN / BLOCKED / ANIMATIC\_LOW), assigned to, decision, decided\_at |
| `dependencies` | artifact → artifacts it was built from; drives invalidation |

Records are append-only: a change creates a new revision; nothing locked is edited in place.

v6.3 adds the table `render_requests` (as RenderRequest, schema 8): id, compiled shot id / revision / hash, kind, provider, model id and snapshot, endpoint, adapter version, parameters, inputs with hashes, prompt text, request\_hash (unique), response id, status, created\_at. Every row of every table is validated against its schema and the cross-field invariants on write.

### Artifact state machine (v6.1)

Every artifact (canonical design, compiled shot, frame, clip, timeline, export) follows one lifecycle:

| From | To | Who | Condition |
| --- | --- | --- | --- |
| DRAFT | VALIDATING | system | all required schema fields present |
| VALIDATING | APPROVED | system | RULE pass and no UNCERTAIN item |
| VALIDATING | REVIEW\_REQUIRED | system | any UNCERTAIN item under the run-mode policy |
| VALIDATING | FAILED / BLOCKED | system | RULE fail (FAILED, retry allowed) or P0 / P1 conflict (BLOCKED, needs a source change) |
| REVIEW\_REQUIRED | APPROVED / FAILED | human reviewer | decision recorded as ReviewDecision |
| APPROVED | FROZEN | system or lock owner | lock record written with hash |
| FROZEN | EXECUTED | worker | render or export finished and stored |
| any | STALE | system | a source it depends on got a new revision; dependants are re-queued by the invalidation rules |

**Concurrency:** every transition is a single database transaction that checks the expected current state and a `version` column (optimistic locking); a second worker attempting the same transition gets a conflict and stops. Render jobs take a per-artifact lock key before calling a model API, so one compiled shot is never rendered twice in parallel. Frozen artifacts are immutable; only a new revision can change them.

## Operations

### Model settings by role

| Role | Temperature |
| --- | --- |
| Extraction, schema repair, routing decisions | 0 |
| Creative (Phases 2, 3, 3B, 4, 5) | 0.7–0.9 |
| Image and video prompt assembly (7, 8) | 0.2–0.4 |
| Judges | 0, and a different model or call than the writer |

### Prompt repository

- One prompt file per phase under version control with a changelog; production uses only versions tagged “stable”.

### Regression suite

| Article type | Expected |
| --- | --- |
| Rich design article | normal run, high novelty and plausibility scores |
| Thin article | `THIN_SOURCE`, more original departures |
| Article about a famous yacht | strong must-avoid list; novelty vision check passes |
| Article with brand-heavy content | no brand in any output |
| Article that is not about yachts | Phase 0 REJECT |
| Vietnamese-language article | correct extraction |

- Small change → run the suite to the end of Phase 6 (no renders).
- Large change or changes to Phases 7–10 → full run on 2 representative articles.
- Promote a version only if no RULE pass rate drops and the mean judge score does not fall by more than 0.3.

### Error handling

| Error | Handling |
| --- | --- |
| Timeout or server error | retry up to 3 times with backoff |
| Rate limit | queue and wait per the provider’s retry hint |
| Content-policy refusal (image / video) | rewrite the prompt by rule (drop trigger words, keep anchors), log the term to an avoid list; second refusal → coverage or fallback |
| Malformed JSON from an LLM | resend with the exact schema error, max 2 times |
| Wrong aspect / resolution | reject and re-call with validated parameters |
| Preview model changes behavior or is renamed | profile check fails at Phase 0 → switch routing to the other tool, alert |
| Provider outage | pause the project, persist state, resume later |

### Parallelism and caching

- The pipeline map is a dependency graph; any step with all inputs ready runs.
- Per-tool concurrency limits from account quotas; HARD shots first; type C shots in chain order; coverage last.
- Job key = hash of all inputs (assembled prompt, references, edit inputs, seed, parameters); identical key → reuse the result.

### Monitoring and alerts

| Metric | Alert when |
| --- | --- |
| Cost per film | > 80% of budget |
| Gate pass rate per phase | below the 10-project average |
| Average render rounds per shot | > 2 |
| Fallback rate | > 20% |
| Phase duration | > 2× average |

### Assets, licenses, security

- API keys in a secret manager, never in prompts or the manifest.
- One folder per project; keep sources, intermediates (e.g. 30 days) and masters (permanent).
- Store license evidence for music, SFX and fonts with the project.
- Store the article and `inspiration` for provenance.

## Pilot runs and acceptance

The pipeline is “stable” only after four pilot rounds and all acceptance criteria below. The pilots also calibrate every starting threshold in this document (0.70, 0.80, 0.85, 0.90…).

### Pilot rounds

| Round | Scope | Mode | Goal |
| --- | --- | --- | --- |
| 1 — Dry run | 5 articles, Phase 0 → 6, no renders | REVIEW\_ALL | schemas, RULE pass rates, novelty and plausibility scores, LLM cost |
| 2 — Calibration | 1 article, full | REVIEW\_ALL | humans label \~60 images and \~40 clips pass / fail; tune VISION thresholds until machine and human agree ≥ 85%; verify reference consistency and tool routing |
| 3 — Reviewed run | 2 articles, full | REVIEW\_KEY | stops, fallback rate, cost and time per film |
| 4 — Unattended run | 2 articles, full | FULL\_AUTO | blind comparison with round 3 |

Round 1 target: ≥ 90% of outputs pass RULE gates first time; no phase needs more than 2 regenerations.

### Defect log

Per defect: article · phase · symptom · cause · prompt or config changed · new version. A defect seen in ≥ 2 articles must be fixed before the next round.

### Acceptance criteria

| Criterion | Threshold |
| --- | --- |
| Phase 0 rejects unsuitable articles | 100% |
| Novelty judge (Phase 2) on pilot designs | ≥ 8 / 10 for every film |
| Designs judged “already exists” by blind viewers shown well-known superyachts | ≤ 1 in 5 viewers |
| Article facts or real names on screen | 0 |
| Stray text or faces in final films | 0 |
| Voice detected in final mix | 0 |
| Yacht consistency: blind viewers notice shape changes | ≤ 1 in 5 viewers |
| Room consistency between bare / fit-out / finished pairs | judged correct in ≥ 90% of pairs |
| Fallback shots | ≤ 20% |
| Final QC story score | ≥ 9 / 12 for every film |
| Cost per film | ≤ Phase 0 budget |
| FULL\_AUTO vs REVIEW\_KEY in blind review | ≤ 1 point difference on a 10-point scale |
| Test publish: average % viewed | ≥ the channel’s current average |

v6 additional acceptance criteria:

| Criterion | Threshold |
| --- | --- |
| Compiled specs with state conflicts reaching Phase 7 | 0 |
| Published films with a failed P0 constraint | 0 |
| Landmark deviation on sampled hero frames | ≤ 3% of LOA |
| Novelty verdict of pilot designs | ORIGINAL (or UNCERTAIN resolved by a human) |
| Approved reference inputs recorded per image tool | yes |
| Lock records with hash and approver for every lock | 100% |
| UNCERTAIN items resolved before publish | 100% |

### After acceptance

- Write calibrated thresholds and tool routing into the Phase 0 config.
- Tag every prompt “stable”.
- The pilot articles become the regression suite.

## Sources for tool notes (as of October 2026)

Tool capabilities change quickly; re-verify at Phase 0.

- GPT Image 2: [What is GPT Image 2 (MindStudio)](https://mindstudio.ai/blog/what-is-gpt-image-2) · [ChatGPT Images 2.0 (Neurohive)](https://neurohive.io/en/news/chatgpt-images-2-0-openai-launches-image-generation-model-with-reasoning-2k-resolution-and-multilingual-text/) · [GPT Image 2 model page (OpenRouter mirror)](https://api3.shopot.ai/openai/gpt-image-2)
- Nano Banana Pro / 2: [Nano Banana series developer guide (APIYI)](https://docs.apiyi.com/en/api-capabilities/nano-banana-dev-guide.md) · [Nano Banana Pro API (fal)](https://fal.ai/docs/model-api-reference/image-generation-api/nano-banana-pro.md) · [Model comparison (useapi)](https://useapi.net/docs/api-runwayml-v1/post-runwayml-images-create.html)
- Veo 3.1: [Veo 3.1 API modes (Unifically)](https://unifically.com/models/veo-3.1) · [Veo options (Puter docs)](https://docs.puter.com/AI/txt2vid/) · [Veo 3.1 (Runware docs)](https://runware.ai/docs/models/google-veo-3-1)
- Gemini Omni 1.1 Flash: [Model reference (Oxen)](https://docs.oxen.ai/inference-api/reference/models/gemini-omni-1-1-flash.md) · [Explainer (eesel)](https://www.eesel.ai/blog/gemini-omni-1-1-flash) · [Launch coverage (Northeast Times)](https://northeasttimes.com/2026/08/28/google-s-gemini-omni-1-1-flash-brings-controllable-ai-video-to-developers/) · [1080p / 4K options (Crypto Briefing)](https://cryptobriefing.com/google-omni-flash-1080p-4k-video/)

Official documentation used to verify model IDs in v6.1:

- [GPT-Image-2 model page (OpenAI)](https://developers.openai.com/api/docs/models/gpt-image-2.md)
- [Gemini Omni Flash model page (Google AI for Developers)](https://ai.google.dev/gemini-api/docs/models/gemini-omni-flash) · [Generate and edit videos with Gemini Omni Flash](https://ai.google.dev/gemini-api/docs/omni) · [Video generation overview](https://ai.google.dev/gemini-api/docs/video.md.txt)
- [Veo 3.1 in the Gemini API](https://ai.google.dev/gemini-api/docs/video?hl=ko) · [Veo 3.1 on Vertex AI](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/models/veo/3-1-generate?hl=zh-cn)
- [Nano Banana image generation (Gemini API)](https://ai.google.dev/gemini-api/docs/nanobanana?hl=ja)
