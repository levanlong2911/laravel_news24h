# Scene–Shot Contract · Versioning · Approval

**Status:** specification agreed in principle. No runtime code changed, no migration run.
**Scope:** the new production flow (screenplay → shot breakdown → render). Legacy data keeps its own path.

Lines marked **[VERIFIED]** are facts read from the code, with `file:line`.
Lines marked **[PROPOSED]** do not exist yet.

---

## 1. Four objects, four responsibilities

| Object | Decides |
|---|---|
| **Screenplay scene** | place, time, participants, action, development |
| **Production scene** | links a screenplay scene to one breakdown revision |
| **Shot** | how part of a scene is shown — image, sound, camera |
| **Render** | **one request** for media, carrying a frozen input snapshot |
| **Attempt** | **one execution** of that request — a render has many attempts |

Shot ≠ render ≠ attempt. One shot may have several renders (content changed, model changed); one render has several attempts (retry on the same snapshot).

A film has **3–30 scenes**, each scene has **1–10 shots**.

All five stages required ⇒ if each scene belongs to exactly one stage, the practical minimum is **5 scenes**, not 3.

A shot **never** adds an event, subject or place outside the approved scene. To change an event, or to merge/split scenes, send the request back to the screenplay.

---

## 2. Identity chain

```
video_planning_stages   stage=screenplay · planning_revision=R · content_hash=Hs
    scenes[].id = sc_NN
        │  (only when R is APPROVED and SELECTED)
        ▼
video_planning_stages   stage=scene_plan · planning_revision=P · content_hash=Hp
    header for one breakdown revision                       [PROPOSED role]
        │
        ▼
video_render_scenes     project_id · revision=P
    + screenplay_stage_id · screenplay_scene_code · screenplay_hash   [PROPOSED columns]
        │
        ▼
video_shots             scene_id → video_render_scenes.id            [VERIFIED]
    shot_code · shot_index · plan_revision=P
        │
        ▼
video_design_images     + shot_id                                    [PROPOSED column]
        │
        ▼
video_renders           shot_id                                      [VERIFIED]
```

**[VERIFIED]** `video_shots.scene_id` (FK → `video_render_scenes`) and `shot_index` already exist, from `2026_08_18_150000:39-40,55`. The one-scene-many-shots relation is already in the schema.

**[VERIFIED]** `shot_index` has **zero references** in `app/` and `tests/`. Adding it to `$fillable` + cast is safe.

**[VERIFIED]** `scene_sequence_index` **currently holds `scene_index`** (`SceneShotFactory:66`) and is read as `sequenceIndex` (`SceneExecutionPacketBuilder:61`). **Do not** repurpose this column.

**Assembly order** = `render_scene.scene_index` → `shot.shot_index`.

---

## 3. Four version axes — never mixed

| Axis | Meaning | Increments when |
|---|---|---|
| **Contract version** | shape of the data and its rules | schema/contract changes |
| **Screenplay revision** `R` | one screenplay content version | the screenplay is rewritten |
| **Plan revision** `P` | one breakdown bound to a specific `R` | breakdown is rerun |
| **Identity version** | a version of **the same** subject | that subject's appearance changes |

`Identity version` is a secondary axis; it does **not** replace `contract version`.
An identity `version` is **never** used to distinguish two different subjects.

Never change the meaning of an old contract in place. Old data is read under its own contract, or converted deliberately.

---

## 4. Two kinds of hash, not interchangeable

| Hash | Used for |
|---|---|
| **Raw response hash** | reconciling a model call |
| **Normalised content hash** | approval, version comparison, dependency checks |

Normalisation rules:

- Sort object keys; **preserve array order** — scene/shot order carries meaning.
- Include the production content **and** the contract version.
- Exclude operational metadata: usage, timestamps, approval state.
- **Never** silently rewrite text before hashing.

**[VERIFIED]** `PlanningStageStore::finishSucceeded()` writes `output_hash = hash('sha256', $rawResponse)` — that is the **raw hash**, not the approved content hash.

**[PROPOSED]** The content hash lives in the planning stage's output metadata. The server **recomputes** it before approval and before selecting a production version.

---

## 5. Approval ≠ selecting the production version

Two distinct actions:

- **Approval** confirms that one specific content version is acceptable.
- **Production selection** decides which version is currently being produced.

A new draft **does not** affect the version in production. Approved content is **never edited in place** — an edit creates a new revision.

### 5.1 Storing approval decisions

**[VERIFIED]** `video_review_decisions` already exists (`2026_08_18_120000:69-88`), has **no model**, and has **zero reads/writes** in `app/`.

```
entity_type   screenplay | scene_plan | shot | render | final
entity_id     uuid        ← UUID ONLY; cannot hold a (project_id, revision) pair
revision · reviewer_id · decision · reason · metadata_json
```

**[VERIFIED]** `entity_id` is declared `uuid`. There is **no `content_hash` column**.

**[PROPOSED]**
- Screenplay approval → `entity_id` = UUID of the `video_planning_stages` row, stage=`screenplay`.
- Breakdown approval → `entity_id` = UUID of the `video_planning_stages` row, stage=`scene_plan`.
- `content_hash` + `contract_version` live in `metadata_json`, **checked on every read**.
- "Insert only" must be **enforced in code**; writing it in a document does not make it true.

### 5.2 Breakdown header

**[PROPOSED]** Reuse the `video_planning_stages` row with stage=`scene_plan` as the object representing one plan revision. Do not create a new header table merely because the scenes live in several rows.

Conditions:
- Record the screenplay source: stage UUID + revision + content hash.
- Allow approval only when **the whole** breakdown for that revision is complete and valid.
- If generated in several scene groups, an incomplete group is **not** a complete plan.
- Every production scene and shot must trace back to this header.

### 5.3 Production selection pointers

**[PROPOSED — new columns on `video_projects`]**

```
selected_screenplay_stage_id
selected_scene_plan_stage_id
production_selection_version
```

Two separate pointers, because there is a stage where a screenplay is selected but no breakdown exists yet.

Switching must:
1. Check permission, project, completion state, and an approval decision with **a matching hash**.
2. Check that the plan belongs to **the selected screenplay**.
3. Lock the project inside a transaction and compare `expected_selection_version`.
4. Increment the version and record the selection history; a stale request is **rejected**, never merged.
5. **Never** delete a previous plan, image or clip.

---

## 6. Subject mapping

**[VERIFIED]** `video_visual_identities` has `UNIQUE(project_id, identity_type, version)` (`2026_08_18_110000:40`). Two characters with `identity_type='subject'` and `version=1` **collide**.

**[VERIFIED]** `VisualIdentityStore` looks up by `project_id + identity_type` in **all three** places:
- `:17-19` latest identity
- `:53-56` find again by hash
- `:67-70` increment version

**None of them distinguishes individual subjects.** Changing the unique key alone still returns the wrong subject.

**[PROPOSED]**
- The server issues a **stable subject key**, separate from the display name and from the `ch_*` code.
- The mapping is identified by **screenplay stage UUID + character/location code**.
- A new screenplay **may propose** reusing an existing subject; an uncertain case **must be confirmed by a person**. Never auto-match on the code.
- Identity version increments when the **identity content** changes, not when a line of dialogue changes.
- Fix **all three** `VisualIdentityStore` queries to carry the subject scope.

Locations follow the same principle, but **build state is a variant of the same location** — do not create a new location merely because the hall goes from empty to occupied.

---

## 7. Environment

**[VERIFIED — corrects an earlier wrong conclusion]** The `milestone_keys → environment` path is **live**, not dead:

```
config  video.scene_plan.profiles.yacht  = vessel_v1   ← planning
config  video.environment.profiles.yacht = vessel_v2   ← ENVIRONMENT

VideoProjectService:2214-2226   environmentProfile() loads vessel_v2
vessel_v2.json                  5 environments; 20/20 milestones map to one
```

The earlier "dead path" conclusion was wrong because it was tested against `vessel_v1` (0 environments) while the environment path loads `vessel_v2`.

**Consequence:** `milestone_keys` **must not be removed** until the new path works and is proven.

**[PROPOSED] New path** — not simply `location_id → one image`:

```
stable location  +  build state to be shown
          ↓
approved asset version
          ↓
the specific artifact used by the shot
```

If the new environment is missing, **report it missing**. **Never** silently fall back to milestones and pick a different plate.

---

## 8. Continuity

- A scene must have enough shots to carry the approved content — not enough to fill a quota.
- The relation between shots is stated: continuation · cut · time skip · other supported relation.
- **Do not** require every following scene to open in the state the previous one closed — that blocks legitimate place changes and time skips.
- A continuation must keep state compatible; a time skip must make the progress legible.
- A coverage item must point at the scene/shot that carries it. **Declaring an ID does not prove the content is there.**
- Camera limits are stated **in the input**; never let the model choose movement and then silently coerce it to `locked`.

---

## 9. Three result concepts — not interchangeable

| Concept | What it is | Stored in | Read by |
|---|---|---|---|
| **Current request** | the render the shot is **waiting on** | `shot.current_render_id` **[proposed]** | monitoring screen |
| **Result history** | the **set of records** of every render + attempt, stale ones included | `video_renders` · `render_attempts` **[existing]** | history screen |
| **Selected result** | the specific render/artifact used for assembly | `shot.video_render_id` **[existing]** | final composition |

History is a **set of records**, not a pointer.

**[VERIFIED]** `video_shots.video_render_id` is documented in the model as *"Clip dang duoc tinh la cua shot nay"* — its **meaning is already "selected result"**. What is wrong is the **write condition**, not the name.

**[VERIFIED]** `VideoShotCheckpointService::markVideoReady()` only checks `execution_status === SUCCEEDED` before writing `video_render_id`. It does not check `execution_purpose`, and does not check that the render is still the current request. Its caller `VideoRenderExecutionService:473` **does not filter canary**.

**[VERIFIED]** `sceneClipCells()` deliberately takes the **latest attempt whatever its state** — correct for monitoring. `finalCompositionCells()` consumes those same cells and skips anything not `succeeded` ⇒ **a rerun that is running or failed hides an earlier successful clip**. Live bug.

**[PROPOSED]** Auto-selection on completion requires **six** conditions. **Keep the existing check and add the new ones — do not replace.**

```
render.shot_id            === shot.id
render.execution_purpose  === production
render.execution_status   === SUCCEEDED            ← EXISTING CHECK, KEEP IT
render.id                 === shot.current_render_id
shot.auto_select_version  !== null
shot.auto_select_version  === shot.intent_version
```

Check and write inside **one transaction** (CAS). After applying, set `auto_select_version = null`, so a replayed `complete()` cannot create a new selection.

### 9.1 Who updates the three columns — the whole loop, not just the checkpoint

| Where | What it does |
|---|---|
| **Dispatch** (production) | set `current_render_id`, increment `intent_version`, grant `auto_select_version = intent_version`. A **reused render already `SUCCEEDED`** → run the selection logic immediately in the same transaction; do not wait for a new completion |
| **Dispatch** (canary) | touches **none** of the three columns, and not `video_render_id` |
| **Input writers** (`SceneShotFactory`, approving a source image, …) | only when the input **actually changed**: increment `intent_version`, `current_render_id = null`, `auto_select_version = null`, mark `video_render_id` **needs reconciliation** — **never delete** |
| **Manual selection** | increment `intent_version`, `auto_select_version = null`, write `video_render_id`, record the decision. A running render is **still monitored**, but its completion **must not override** the manual choice |
| **Checkpoint** | the six conditions above |
| **Readers** | Clips reads the **current request**; final composition reads the **selected result that is still valid** |

Fixing only the checkpoint is not enough — it has no trustworthy state to compare against if dispatch never set one.

### 9.2 A new operation ≠ a replay of the same operation

**Three kinds of idempotency, none replaces another:**

```
operation_id     one USER request                  → replay returns the earlier result
intent_version   the shot's concurrency token      → prevents POINTER HIJACK
request_hash     the RENDER's input                → prevents PAYING TWICE
```

**[VERIFIED]** `request_hash` + `idempotencyKey()` already exist and run. The other two are new. A **new** `operation_id` **may** reuse the same render — that is correct behaviour.

**[PROPOSED] Dispatch order — do not reorder:**

```
1  authenticate + authorise
2  lock the shot inside a transaction
3  LOOK UP THE EXISTING OPERATION by operation_id     ← BEFORE the version check
     ├─ found  →  compare the stored payload hash
     │              different  →  REJECT (the id is being reused for other work)
     │              identical  →  return the earlier result; do NOT increment the
     │                            version, do NOT change current, do NOT re-grant
     │                            auto-select
     └─ not found  →  continue
4  check expected_intent_version
     mismatch  →  409, the data has changed
5  create / reuse the render · increment intent_version
   set current_render_id · grant auto_select_version
6  record the operation event (id + payload hash + result) IN THE SAME transaction
7  reused render already SUCCEEDED  →  apply the six conditions of §9 now
8  COMMIT, and only then queue work for the worker
```

Two orderings matter, and they are different rules:

- **The operation lookup precedes the version check.** Checking the version first makes a legitimate retry of an **already successful** operation return 409 instead of replaying idempotently.
- **The payload comparison happens inside the lookup, before any result is returned.** Returning the earlier result first would hand a stale result to a request that carries different work under a reused id.

**Never call the provider inside the transaction.** The worker picks up work only after commit.

| Situation | Outcome |
|---|---|
| same `operation_id` sent twice | exactly **one** state change; the second returns the earlier result |
| manual selection, then a stale dispatch retry | recognised as the old operation → returns the earlier result; **the manual choice is not overridden** |
| same `operation_id`, different payload | **rejected** |
| two **new** operations with the same `expected` | one succeeds, one gets 409 |
| a new operation reusing a render already `SUCCEEDED` | selects immediately; its own retry **does not** reselect |

**[PROPOSED] Where operation events live**

Keep the two kinds apart; do not force them into one table:

```
USER OPERATIONS        → video_session_events    (a table for events)
RECONCILIATION RULINGS → video_review_decisions  (a table for decisions)
```

**[VERIFIED]** `video_session_events` is **not yet an idempotency store**: it has only `index(session_id, created_at)` and **no unique index at all**. A shot has a `session_id` (`SceneShotFactory::sessionFor()`), so the table **fits semantically** — but it needs the minimum below before use:

```
⬜  the uniqueness scope for operation_id, and the matching UNIQUE index
⬜  where the payload hash and the operation RESULT are stored for replay
⬜  protection against two concurrent requests writing a duplicate
⬜  writing the event and changing the shot in THE SAME transaction
```

Putting `operation_id` in a JSON column and calling it deduplicated is **not enough**.

### 9.3 Reconciling the selected result

**[PROPOSED]** One **shared reconciler** used by both writers and readers, returning **three states**, not a boolean:

```
valid                enough evidence, meets the requirements
stale                evidence exists, but no longer matches
needs_confirmation   not enough evidence to decide
```

The reconciler checks **three groups**:

```
A  Ownership and integrity       r.shot_id = shot.id · purpose = production
                                  status = SUCCEEDED · artifact present, hash matches
B  Content and current dependencies, against the stored render_request_json:
                                  compiled_prompt
                                  motion_spec_hash        ← hash ↔ hash
                                  source_artifact_id · source_artifact.scene_id
                                  end_frame — BOTH directions, see below
C  Technical requirements of the selected production version
                                  aspect ratio · resolution · composition duration
```

#### End-frame — reconcile both the snapshot and the current requirement

Reconcile the end-frame that **the snapshot used** *and* **the continuity constraint in force now**. A request that carried no end-frame is **not** automatically valid.

| Snapshot | Current requirement | Verdict |
|---|---|---|
| no end-frame | none required | ok |
| no end-frame | **now required** | **not `valid`** — the old render could not have honoured a constraint that did not exist yet |
| end-frame X | still requires X | ok |
| end-frame X | now requires **Y** | **not `valid`** — reconcile against the new constraint |
| end-frame X | **none required** | **not automatically stale** — the clip is not wrong for having used X; check whether the content still fits |

**Where the requirement comes from:**

> The current continuity requirement comes from **the selected production version**, independent of whichever model is queued for the next attempt. A model's capability decides **how** the requirement is met, never **whether** it exists. The snapshot records how the earlier render met it.

Without that boundary the two rules collide: switching to a model that supports `last_frame` would retroactively invalidate good clips, and switching to one that does not would make a continuity requirement vanish.

#### Model and settings — two different kinds of change

| Change | Effect on the selected clip |
|---|---|
| changing the **model** to try a different render | does **not** by itself make the old clip stale, and does **not** add or remove a continuity requirement |
| changing **controls** for the next attempt | does **not** by itself change the production requirement |
| changing `action` · `motion` · source image · required continuity | **content must be reconciled** |
| changing **duration inside the shot spec** | **must be reconciled** — already inside `motionSpecHash()` |
| changing aspect ratio / resolution / duration **of the composition** | **technical compatibility must be checked** (group C) |

**[VERIFIED]** `duration_seconds` appears in **two places with different meanings**:
`SceneClipDispatchService:224` — inside the request, taken from `$controls`/registry ⇒ **an option for the next attempt**.
`:587` — inside `motionSpecHash()`, taken from `$spec['duration_seconds']` ⇒ **shot content, must be reconciled**.

**Not every technical difference forces a rerender.** A clip may satisfy the requirement through an **approved** trim or conversion — but it must **never be silently assumed** to fit.

#### Fingerprint

The fingerprint recorded in a reconciliation ruling must contain:

```
the result being judged (specific render + artifact)
the current dependency set        ← EXACTLY what the reconciler compares, no more, no less
the rule version (contract version)
```

An old ruling **never carries over** to a different artifact or a different production requirement.

#### Human acceptance

Human acceptance only resolves cases **the policy allows** — that is, the `needs_confirmation` branch.

It **never** waives: wrong ownership · a missing or corrupt artifact · a render that is not `SUCCEEDED`.
**A manual selection does not waive** the compatibility check.

#### Two verified traps

**[VERIFIED]** Do **not** recompute `request_hash` for comparison: `preflight()` always builds a **CANARY-shaped** request (`freezeRequest(..., PURPOSE_CANARY, ...)`), so its hash differs from the production hash for any model with `last_frame` off. Comparing **field by field** is the correct method.

**[VERIFIED]** `render_request_json` is stored alongside `request_hash` at `RenderDispatchService:130` — enough data to compare.

#### Consequences

- `render_request_json = NULL` (older rows) ⇒ `needs_confirmation`, handled by the **approved legacy policy** — never blocked silently.
- Never backfill by guessing.
- The result may be cached **for display only**; the source of truth is always the reconciler.
- `successorFrame()` is a query, and **each shot may have a different successor**. Load it **in batch**, or memoise on the **full dependency key** (project + plan revision + scene + shot), never on the screen alone. `VideoProjectService` already has `sourceMemo`, but the key must carry every dependency — one successor must never be reused across shots.
- **A frozen timeline keeps its specific render/artifact** and never follows `current_render_id`.

---

## 10. Retry, failure, legacy

**[VERIFIED]** The render infrastructure already exists and is rigorous — leave it alone:
- `RenderCheckpointService::complete()` checks the claim token, the lease (twice), `request_hash`, the manifest, and the state machine.
- `RenderClaimService::claimRow()` increments `attempt_count` + `claim_generation` (fencing) and creates a `VideoRenderAttempt`.
- `SceneClipDispatchService::idempotencyKey()` reuses any render not FAILED/CANCELLED; if a receipt exists it **throws** rather than paying twice.
- Dispatch stores `request_hash` **and** `render_request_json`.

Rules:
- Retry the failed part by a named scene group; keep raw, usage and error.
- **A timeout proves neither failure nor that the work was free** — never resend a paid request that has not been reconciled.
- A stale result is **still recorded** in history; it is only barred from becoming the selected result.

Legacy:
```
render_scene.screenplay_hash = NULL   not linked under the new contract — NOT automatically barred
design_image.shot_id         = NULL   MUST also consider image_type and render_scene_id;
                                      anchor/reference/environment images do not belong to a shot
shot.plan_revision           = 0      not yet version-scoped
```

Whether to bar legacy data is a **product decision**, never a side effect of a migration.

---

## 11. Profile `yacht_v1` — draft

**[PROPOSED]** The application does not read it yet. `vessel_v1` and `vessel_v2` are **untouched**.

`yacht_v1` is usable for **writing screenplays** before the new environment path is finished; only the **whole production flow** cannot move onto it yet.

```json
{
  "profile_version": "yacht_v1",
  "subject_class": "superyacht",
  "scope": "full_lifecycle",
  "objective": "Create an original, physically credible documentary-style film about a distinctive fictional superyacht, from collaborative design through construction, finishing, delivery and guest experience.",

  "originality": {
    "whole_vessel_design_required": true,
    "verified_market_novelty_claim_allowed": false,
    "reproduce_source_names_brands_dates_measurements": false
  },

  "arc_stages": ["design", "construction", "finishing", "completion", "operation"],
  "arc_required_stages": ["design", "construction", "finishing", "completion", "operation"],
  "screenplay_limits": { "min_scenes": 3, "max_scenes": 30 },
  "shot_limits": { "min_per_scene": 1, "max_per_scene": 10 },

  "identity_dimensions": [
    "Hull proportions, sheer and freeboard",
    "Bow geometry",
    "Superstructure massing",
    "Glazing and openings",
    "Stern and terraces",
    "Deck relationships, circulation and signature spaces"
  ],

  "people_policy": {
    "design": ["design team", "engineering representatives"],
    "construction": ["site engineers", "trade crews"],
    "finishing": ["outfitting teams", "quality representatives"],
    "completion": ["yard representatives", "owner representative", "captain"],
    "operation": ["operating crew", "service crew", "guest group"],
    "recurring_representatives": true,
    "declare_visible_participants": true,
    "background_groups_allowed": true,
    "fixed_people_count_per_scene": false
  },

  "coverage_policy": {
    "one_item_does_not_equal_one_scene": true,
    "required": "Show observable action or a visible result supporting the item.",
    "shown_or_transition": "Show the item or explain an intelligible time transition covering its progress.",
    "not_applicable_requires_human_approval": true,
    "coverage_claims_require_editorial_review": true
  },

  "coverage": [
    { "id": "cov_design_brief",        "stage": "design",       "level": "shown_or_transition", "label": "Establish the design question and constraints." },
    { "id": "cov_design_decision",     "stage": "design",       "level": "required",            "label": "The design team settles the vessel's distinguishing architectural idea." },
    { "id": "cov_design_arrangement",  "stage": "design",       "level": "shown_or_transition", "label": "Establish coherent deck levels, spaces and circulation." },
    { "id": "cov_design_engineering",  "stage": "design",       "level": "shown_or_transition", "label": "Design and engineering coordinate the proposed arrangement without invented certification claims." },
    { "id": "cov_design_release",      "stage": "design",       "level": "required",            "label": "The yard receives the agreed design and understands the work to execute." },

    { "id": "cov_build_berth",         "stage": "construction", "level": "shown_or_transition", "label": "Establish a prepared worksite, supports, access and staged material." },
    { "id": "cov_build_bottom",        "stage": "construction", "level": "shown_or_transition", "label": "Show or establish progress of the lower hull structure." },
    { "id": "cov_build_frames",        "stage": "construction", "level": "shown_or_transition", "label": "Show or establish the framing that gives the hull its volume." },
    { "id": "cov_build_plating",       "stage": "construction", "level": "required",            "label": "Crews enclose supported hull structure while retaining the design's defining openings." },
    { "id": "cov_build_deck",          "stage": "construction", "level": "shown_or_transition", "label": "Establish the decks and their spatial relationships." },
    { "id": "cov_build_superstructure","stage": "construction", "level": "required",            "label": "Crews realize the distinctive superstructure form." },
    { "id": "cov_build_machinery",     "stage": "construction", "level": "shown_or_transition", "label": "Establish machinery and service installation with credible access." },
    { "id": "cov_build_check",         "stage": "construction", "level": "required",            "label": "Check completed work; if a deviation is shown, resolve it before dependent work proceeds." },
    { "id": "cov_build_handover",      "stage": "construction", "level": "shown_or_transition", "label": "Outfitting teams receive the relevant completed work areas." },

    { "id": "cov_fin_fairing",         "stage": "finishing",    "level": "shown_or_transition", "label": "Establish surface preparation and coating progress." },
    { "id": "cov_fin_glazing",         "stage": "finishing",    "level": "shown_or_transition", "label": "Establish glazing installation consistent with the vessel's design." },
    { "id": "cov_fin_interior",        "stage": "finishing",    "level": "shown_or_transition", "label": "Establish interior fit-out, materials and usable circulation." },
    { "id": "cov_fin_signature",       "stage": "finishing",    "level": "required",            "label": "Show the signature spaces becoming complete and recognizable." },
    { "id": "cov_fin_snag",            "stage": "finishing",    "level": "shown_or_transition", "label": "Resolve outstanding work if present; do not invent defects." },
    { "id": "cov_fin_accept",          "stage": "finishing",    "level": "shown_or_transition", "label": "Teams review finished spaces without implying unsupported technical approval." },

    { "id": "cov_comp_whole",          "stage": "completion",   "level": "required",            "label": "Reveal the completed vessel as a coherent architectural whole." },
    { "id": "cov_comp_launch",         "stage": "completion",   "level": "shown_or_transition", "label": "Establish launch and the transition from supported hull to afloat vessel." },
    { "id": "cov_comp_trial",          "stage": "completion",   "level": "shown_or_transition", "label": "Establish trial activity without inventing performance figures or certification." },
    { "id": "cov_comp_delivery",       "stage": "completion",   "level": "required",            "label": "Show meaningful transfer to the owner representative and operating crew." },

    { "id": "cov_op_crew",             "stage": "operation",    "level": "shown_or_transition", "label": "Show or establish crew operation and guest service." },
    { "id": "cov_op_guests",           "stage": "operation",    "level": "required",            "label": "A guest group experiences the specific spatial qualities promised by the design." }
  ]
}
```

**26 items · 9 `required` · 17 `shown_or_transition`.**

Difference from the running configuration:

| | `config/video.php:576` | `yacht_v1` draft |
|---|---|---|
| `arc_required_stages` | 4 stages (no `finishing`) | **5 stages** ⇒ practical minimum of 5 scenes |

`yacht_v1` carries **no** `milestone_groups` and **no** `min_scenes: 10`. Therefore `SceneProfile::load()` **cannot read it** — the new path needs its own loader.

Reason: **[VERIFIED]** the profile's `min_scenes` **gates the output**, not just the load (`VideoProjectService:5220`), and `SceneProfile::toPayload()` (`:287`) pushes `milestone_groups` **straight into the model's user message**. Padding the file to satisfy the loader would force ≥10 scenes and re-inject the 20-milestone ontology into the prompt.

---

## 12. Screenplay v3 — application validation rules

JSON Schema itself does support conditionals (`if`/`then`/`else`) and cardinality caps. The schema still stays structurally simple, and **the application validator carries the cardinality and cross-reference rules**. Written here so writer and reader cannot drift.

**[CORRECTION]** An earlier version of this section said the provider's structured-output subset is narrower than the specification. Nothing in this repository establishes that. The screenplay path sends its schema unchanged — `AnthropicStructuredOutputClient` passes `outputSchema` straight through, and only the concept path runs `ClaudeSchemaAdapter`, which strips fifteen keywords for its own reasons. What is observed: a paid v2 run went through with `minLength`, `maxLength`, `pattern` and `minItems` in the schema, so the endpoint does not reject them. Whether it **enforces** them is unmeasured, and measuring it costs a paid call.

So, stated without assuming anything about the provider:

> The application does not enforce the whole JSON Schema locally. The schema is sent to the provider; the local checks cover only the constraints that have been implemented.

Implemented locally: `type`, `required`, `enum`, plus the cardinality and cross-reference rules below. Not implemented locally: `pattern`, `minLength`, `maxLength`, `minItems`.

### 12.1 `coverage[].mode`

Every `coverage_id` declared in the profile appears **exactly once** in the output — including the ones being skipped.

| mode | `scene_ids` | `evidence` |
|---|---|---|
| `shown` | **≥ 1**, every id must be a declared scene | what is observable in those scenes |
| `transition` | **exactly 2** — the scene before and the scene after, **in screenplay order** | the progress being skipped between them |
| `not_applicable` | **0** | why it does not apply. Kept in the draft **for a reviewer**; it never satisfies a `required` item and never authorises production |

A `required` item accepts **only** `shown`.

`evidence` is a **claim**, not proof. It is checked against the scene's `action` by a reviewer; no validator can confirm it.

A `transition` names **two different** scenes. Repeating one id is refused, and so is naming them out of screenplay order.

A `not_applicable` item still carries its reason in `evidence`, and the validator raises an **editorial warning** for each one so a reviewer has to accept the reason rather than let it pass unread.

**[CORRECTION]** The schema's `minLength: 10` on `evidence` does **not** reject a string of spaces — JSON Schema counts characters, not content. The application validator therefore requires `trim(...) !== ''` on `evidence`, and on `build_state.state`. Relying on `minLength` alone would let `"          "` through.

### 12.2 `scenes[].build_state`

```json
{ "subject_id": "ch_vessel", "state": "hull plated to the sheer, superstructure not yet set" }
```

- `subject_id` must reference a declared character whose `kind` is **`object`** — build state belongs to the thing being built, never to a person or a group.
- This is the state **at** the scene, not the change since the last one. Two scenes over the same unfinished hull both carry it, so continuity stays checkable.
- `null` only when build state does not apply to the scene, or is not shown in it. **Never** `null` merely because nothing changed, and **never** read `null` as "inherits the previous scene".
- This field serves **storytelling and continuity review**. It is **not** a render-ready environment contract; the environment path (§7) needs its own data shape.

### 12.3 Limits the validator must enforce

**[VERIFIED]** `max_subjects` and `max_locations` are declared in the profile but **enforced nowhere** — `ScreenplayValidator` reads only `min_scenes` / `max_scenes` (`:231-232`). Changing the numbers in JSON does nothing until a check exists.

```
min_scenes / max_scenes        enforced today
max_subjects / max_locations   ⬜ PROPOSED 12 / 12 — awaiting approval AND a check
                               a group counts as ONE subject, not its members
min/max_shots_per_scene        belongs to the breakdown layer, not this validator
```

A profile missing a required limit must **fail loudly before any API call**, never silently disable the check.

### 12.4 Which contract the validator applies

The validator is told the contract **by the caller**, as an explicit argument — it does not infer it from the profile. It refuses an unknown version, and refuses a profile whose `contract_version` disagrees with the version it was handed. Inferring the version from the profile alone would let a v3 run silently fall back to v2 rules, which is the one failure mode this rule exists to prevent.

### 12.5 What the validator guarantees, and what it does not

**[CORRECTION]** "The validator never throws" was too strong a claim to make. What is true, and what the tests hold it to:

- Malformed model output returns **path-tagged errors** (`coverage[3].evidence: …`), not warnings and not exceptions. Shape and type are checked before any iteration, `count()` or string interpolation.
- A missing field and an explicit `null` are **different**. `build_state` absent is an error; `build_state: null` is a legitimate statement that build state does not apply to that scene.
- Nothing guarantees an unexpected throw is impossible. So the **service** wraps everything that runs after the paid response — validation, editorial pass and the write — and on any throw records the attempt with its raw response and usage. The three outcomes are kept apart: the write returned `true` (recorded), returned `false` (the claim was no longer held), or threw (the stored state is **unknown** and must not be reported as recorded).

---

## 13. Three open decisions

```
⬜  Who confirms that a character in two screenplays is the same subject
⬜  Caps on subjects/locations: by recurring role, or by total head count
⬜  New environment path: the data shape of "build state to be shown"
```

These three do **not** block finishing the screenplay layer. They block moving rendering onto the new flow.
