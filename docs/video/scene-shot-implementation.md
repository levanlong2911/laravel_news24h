# Scene–Shot Implementation — rule · storage · files · tests

**Status:** plan. No runtime code changed, no migration run.
Read together with `scene-shot-contract.md`.

---

## 1. Rule → storage → files to change → acceptance test

| # | Rule | Stored in | Files to change | Acceptance test |
|---|---|---|---|---|
| 1 | A scene has 1–10 shots | `video_shots.scene_id` **[existing]** · `shot_index` **[exists, not fillable]** | `VideoShot` (+fillable/cast) · `SceneShotFactory` · `ShotValidator` | `0` and `11` shots → **rejected**; `1` and `10` → valid; `shot_index` **unique** within the same scene + `plan_revision` |
| 2 | Assembly order = `scene_index` → `shot_index` | same | timeline reader · `finalCompositionCells` | reverse the creation order → assembly order unchanged |
| 3 | Re-running the breakdown never overwrites old shots | `UNIQUE(session_id, shot_code, kind, plan_revision)` **[proposed]** | `SceneShotFactory` (look up on all 4 keys) · `VideoShotRepository::updateOrCreateShot` | same revision → no duplicate; new revision → does not touch a shot that already has a render |
| 4 | Shots stay bound to their screenplay source | `video_render_scenes.screenplay_stage_id` · `_scene_code` · `_hash` **[proposed]** | `VideoProjectService::planScenes` | all 3 fields written; **writing a new draft → the live plan's state is UNCHANGED**; only **switching the selected version** triggers dependency reconciliation |
| 5 | A keyframe belongs to one shot | `video_design_images.shot_id` **[proposed]** | `DesignImageStore` (create · dedupe · `approve` · `sameSlot`) · readers · dispatch | approving the keyframe of shot 1 → **does not** supersede the keyframe of shot 2 in the same scene |
| 6 | Character images do not supersede each other | `sameSlot()` + `identity_id` + `shot_id` | `DesignImageStore::sameSlot():535` | approve image A → image B unchanged |
| 7 | Two subjects never collide on the key | `subject_key` **[proposed]** on `video_visual_identities` | `VisualIdentityStore` **all 3 queries** (`:17-19`, `:53-56`, `:67-70`) | 2 characters, same type/version → 2 rows; `latest()` returns the subject that was asked for |
| 8 | Approval is bound to specific content | `video_review_decisions` **[table exists, no model]** · hash + contract version in `metadata_json` | new model `VideoReviewDecision` · approval service | wrong hash → rejected; **two deliberate decisions → two rows**; **replaying one request (double-click/retry) → NO duplicate row**; never UPDATE an existing row |
| 9 | Approval ≠ production selection | `video_projects.selected_*_stage_id` · `production_selection_version` **[proposed]** | production-selection service | a new draft → the version in production is untouched |
| 10 | Production selection is concurrency-safe | `expected_selection_version` (CAS) | same | two concurrent switches → one succeeds, one is **rejected** |
| 11 | Coverage is met | `yacht_v1.coverage` | `ScreenplayValidator` | a missing `required` item → structural failure; a `shown_or_transition` item dropped without a stated transition → warning |
| 12 | A shot never **references** people/places outside its scene | allowlist from `ScreenplayBreakdown` **[new]** | `ShotValidator` **[new]** | a shot declaring a `character_id`/`location_id` outside the allowlist → fail. **Limit:** it cannot catch the model writing *"a second worker"* in prose — that needs the reviewer and the approver |
| 12b | A stale render result never overwrites the current selection | `shot.current_render_id` · `intent_version` · `auto_select_version` **[proposed]** · `video_render_id` **[existing]** | **the whole loop**, see contract §9.1: `SceneClipDispatchService` · `SceneShotFactory` · `DesignImageStore::approve` · manual-selection action **[new]** · `VideoShotCheckpointService::markVideoReady` · `sceneClipCells`/`finalCompositionCells` | A running → B becomes the current request → B finishes → **A finishing later does not move the pointer**; **input changed while A ran** → A's result is not selected; **replayed completion** does not reselect; **reusing a render already `SUCCEEDED`** → selected inside the dispatch transaction; **current failed** → the previous `video_render_id` **stays**, and the screen can state both states; canary touches nothing; manual selection during a running render → its later completion does not override |
| 12c | A replayed operation never hijacks the pointer | `operation_id` + payload hash + result → `video_session_events` **[needs a UNIQUE index]** · `shot.intent_version` (CAS) **[proposed]** | `SceneClipDispatchService` · manual-selection action **[new]** · controller | same `operation_id` twice → exactly **one** state change; manual selection then a stale dispatch retry → **the manual choice survives**; **same ID with a different payload → rejected BEFORE any earlier result is returned**; two **new** operations with the same `expected` → one succeeds, one 409; a new operation reusing a `SUCCEEDED` render → selects, its own retry **does not** reselect. **Separate test:** `idempotencyKey()` still reuses the render and does not pay twice |
| 12d | The selected result is reconciled | **no status column** — one shared reconciler, 3 states (contract §9.3); the evidence goes to `video_review_decisions` | shared reconciler used by writers and readers · `finalCompositionCells` | changing `compiled_prompt` / `spec_json.motion` / source image → `stale`; **an end-frame change is reconciled against the §9.3 table — not every change is `stale`**: snapshot had none and continuity now requires one → **not `valid`**; snapshot used X and nothing requires one now → **not automatically stale**; **changing only the model or next-attempt controls while every production requirement is unchanged → the verdict is unchanged** (a `stale` or `needs_confirmation` clip must never become `valid` this way); changing the composition's aspect/resolution/duration → technical compatibility is checked; editing dialogue that does not touch clip inputs → not invalidated; input changes while a reconciliation is running → the earlier ruling is **not honoured**; changing the selection → the previous render's evidence is **not** reused; current failed but the selection is still valid → **still usable**; `render_request_json = NULL` → `needs_confirmation`, per the legacy policy |
| 13 | Environment never falls back silently | new path **[proposed]**; the old `vessel_v2` path stays | `approvedPlate()` | missing new environment → **report it missing**, never fall back to milestones |
| 14 | Legacy data stays readable | `shot_id=NULL` · `screenplay_hash=NULL` · `plan_revision=0` | readers | old plans still display; a scene-level image is not treated as a shot keyframe |

---

## 2. Schema change items — 8 items, 7 are migrations

Do not run until the ⬜ items in `scene-shot-contract.md §13` are decided.
M8 additionally waits on the four ⬜ items in **§9.2** (uniqueness scope, payload-hash and result storage, concurrent-write protection, same-transaction write).

| # | Table | Change | Risk | Required alongside |
|---|---|---|---|---|
| M1 | `video_shots` | `UNIQUE(session_id, shot_code, kind, plan_revision)` | 🟡 | `SceneShotFactory` must look up on all 4 keys, otherwise it **still overwrites** |
| M2 | `video_design_images` | `+ shot_id uuid null FK` | 🔴 | 5 places must change together: create · dedupe · approve · readers · dispatch |
| M3 | `video_render_scenes` | `+ screenplay_stage_id` `+ screenplay_scene_code` `+ screenplay_hash` | 🟢 | `NULL` = legacy plan, **never barred automatically** |
| M4 | `video_visual_identities` | `+ subject_key`, change `UNIQUE` | 🟡 | fix **all three** `VisualIdentityStore` queries |
| M5 | `video_projects` | `+ selected_screenplay_stage_id` `+ selected_scene_plan_stage_id` `+ production_selection_version` | 🟢 | only takes effect once the selection service exists |
| M6 | *(no migration)* | add `shot_index` to `VideoShot::$fillable`/`$casts` | 🟢 | `shot_index` has **zero references** — safe |
| M7 | `video_shots` | `+ current_render_id` `+ intent_version` `+ auto_select_version` | 🟡 | stops a stale render from overwriting the selection (§9). `video_render_id` **keeps its existing meaning** = selected result |
| M8 | `video_session_events` | `+ operation_id` · `+ payload_hash` · `+ result_json` · `UNIQUE` over the operation scope | 🟡 | the table currently has **no unique index** ⇒ not yet an idempotency store. The uniqueness scope must be decided first (§9.2 ⬜) |

---

## 3. Order of work

```
1  Specification   these two documents + the writer/reader table + tests   ← WE ARE HERE
2  Screenplay      yacht_v1 profile · schema · contract · example · fixtures
                   NO paid API calls
3  Approval        VideoReviewDecision model · screenplay approval
                   production-selection pointers · subject mapping
4  Breakdown       ScenePlanAuthor re-sourced · many shots · allowlist
                   environment by location/state
5  Render          per-shot keyframes · clips · timeline
                   reconciliation when the selected version changes
                   enable the full flow only after the cost gate is confirmed
```

Step 2 **does not wait** for step 4. `yacht_v1` is usable for writing screenplays now; only the full render flow cannot move onto it yet.

---

## 4. Appendix — code audit results

Recorded so this does not have to be rebuilt. Everything here is readable from the repo.

### Three parallel systems

| | Entry point | State |
|---|---|---|
| `VideoPlanningPipeline` | `BuildVideoPlanJob` · `video:build-plan` · `video:benchmark` | wired; Truth Layer → Producer/Director → ScenePlanner → IntentPlanner → TimelinePlanner → RenderPlanAssembler |
| `scene_plan` | the `/scenes/plan` button | **the path that renders today**; `ScenePlanAuthor` + `vessel_v1` milestones → `video_render_scenes` |
| `screenplay` | the "Write screenplay" button | not connected to anything downstream |

### Profiles

```
config video.scene_plan.profiles.yacht   = vessel_v1   planning · 0 environments
config video.environment.profiles.yacht  = vessel_v2   ENVIRONMENT · 5 environments
                                                       20/20 milestones map to one
VideoProjectService:2214-2226   environmentProfile() loads vessel_v2
VideoProjectService:5220        min_scenes GATES THE OUTPUT, not just the load
SceneProfile:287 toPayload()    pushes milestone_groups into the model's user message
```

### Columns that exist but are unused

```
video_shots.shot_index          zero references in app/ and tests/
video_shots.plan_revision       every write lives on the Python path (VideoSessionService)
video_render_scenes.revision    IN USE: max(revision)+1 at VideoProjectService:3536
video_design_images.identity_id exists; sameSlot() does NOT check it
```

### Tables built but never used

```
video_review_decisions   0 reads/writes   entity_id is uuid · NO content_hash column
video_worker_claims      0                a lease shape, NOT a queue
video_session_events     0                session_id NOT NULL; no unique index
video_pipeline_failures  0
app/Video/ShotQa/        0 callers        4 services + 3 models + 4 migrations (2026-08-31)
                                          ApprovedShotRevisionService::freeze() — 9 hashes
```

### Live bugs, verified

```
VideoProjectService:896      $cells[$shot->scene_id] — N shots collapse to 1
finalCompositionCells()      takes the LATEST attempt, then drops it unless succeeded
                             ⇒ a running or failed rerun HIDES an earlier good clip
SceneShotFactory:38-52       forceFill overwrites the shot; never sets plan_revision
markVideoReady()             checks only execution_status; no purpose check,
                             no current-request check
ApproveVideoShot::approve()  does not check $qa->video_shot_id === $shot->id;
                             approved_at != null → returns silently
sameSlot()                   has neither an identity nor a shot dimension
```

### Render infrastructure — keep, do not touch

```
RenderCheckpointService::complete()   claim token · lease ×2 · request_hash · manifest · state machine
RenderClaimService::claimRow()        attempt_count+1 · claim_generation+1 (fencing) · VideoRenderAttempt
SceneClipDispatchService              stores both request_hash and render_request_json
  idempotencyKey()                    reuses any render not FAILED/CANCELLED;
                                      if a VideoProviderSubmissionReceipt exists → THROWS, never pays twice
RenderRetryService::schedule()        keeps the same render, moves it to RETRY_WAIT
```

**Render = a REQUEST with a frozen snapshot. Attempt = one EXECUTION of that request.**

### The Python path

```
routes/api.php:39-55   10 endpoints for the Python Composer/Runner
                       storeFromPython · reportShotResult · apiClaim · apiQueued …
```
The owner has decided to **drop Python**. Removing these 10 routes means:
- `video_shots` keeps **one** content writer (`SceneShotFactory`)
- `plan_revision` has **zero** live writers
- the supersede at `VideoSessionService:765` and the `$wouldOrphan` guard die with it

Remove in **two stages**: delete the routes first (one paste to undo), delete the dead code after.
`video_sessions` does **not** die with it — `SceneShotFactory::sessionFor()` creates one per project, and `VideoFinal` is looked up by `session_id`.
