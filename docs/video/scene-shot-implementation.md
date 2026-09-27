# Scene–Shot Implementation — rule · storage · files · tests

**Status:** implemented in runtime code and verified with isolated tests. The six
scene-shot migrations (`2026_09_25_000100` through `000600`) have run on both the
testing database and the application database.
Read together with `scene-shot-contract.md`.

---

## 1. Rule → storage → files to change → acceptance test

| # | Rule | Stored in | Files to change | Acceptance test |
|---|---|---|---|---|
| 1 | A scene has 1–10 shots | `video_shots.scene_id` · `shot_index` **[implemented]** | `VideoShot` · `SceneShotFactory` · screenplay/scene validators | `0` and `11` shots → **rejected**; `1` and `10` → valid; `shot_index` is ordered within the same scene + `plan_revision` |
| 2 | Assembly order = `scene_index` → `shot_index` | same | timeline reader · `finalCompositionCells` | reverse the creation order → assembly order unchanged |
| 3 | Re-running the breakdown never overwrites old shots | `UNIQUE(session_id, plan_revision, shot_code, kind)` **[implemented]** | `SceneShotFactory` · `VideoShotRepository::updateOrCreateShot` | same revision → no duplicate; new revision → does not touch a shot that already has a render |
| 4 | Shots stay bound to their screenplay source | `video_render_scenes.screenplay_stage_id` · `_scene_code` · `_hash` **[implemented]** | `VideoProjectService::planScenes` | all 3 fields written; **writing a new draft → the live plan's state is UNCHANGED**; only **switching the selected version** triggers dependency reconciliation |
| 5 | A keyframe belongs to one production shot | `video_design_images.render_scene_id` **[implemented]**; each expanded `VideoRenderScene` is one planned shot | `DesignImageStore` create/approve/`sameSlot` · readers · dispatch | approving shot 1's keyframe does **not** supersede shot 2's keyframe; changing it invalidates only the owning shot intent |
| 6 | Character images do not supersede each other | `sameSlot()` + `identity_id` + `shot_id` | `DesignImageStore::sameSlot():535` | approve image A → image B unchanged |
| 7 | Two subjects never collide on the key | `subject_key` **[implemented]** on `video_visual_identities` | `VisualIdentityStore` all lookup/version queries | 2 characters, same type/version → 2 rows; `latest()` returns the subject that was asked for |
| 8 | Approval is bound to specific content | `video_review_decisions` · hash + contract version in `metadata_json` **[implemented]** | `VideoReviewDecision` · `ScreenplayApprovalService` | wrong hash → rejected; **two deliberate decisions → two rows**; **replaying one request (double-click/retry) → NO duplicate row**; never UPDATE an existing row |
| 9 | Approval ≠ production selection | `video_projects.selected_*_stage_id` · `production_selection_version` **[implemented]** | `ProductionSelectionService` | a new draft → the version in production is untouched |
| 10 | Production selection is concurrency-safe | `expected_selection_version` (CAS) | same | two concurrent switches → one succeeds, one is **rejected** |
| 11 | Coverage is met | `yacht_v1.coverage` | `ScreenplayValidator` | a missing `required` item → structural failure; a `shown_or_transition` item dropped without a stated transition → warning |
| 12 | A shot never **references** people/places outside its scene | allowlist from the selected screenplay **[implemented]** | `VideoProjectService::validatedScenes()` | a shot declaring a `character_id`/`location_id` outside the allowlist → fail. **Limit:** it cannot catch the model writing *"a second worker"* in prose — that needs the reviewer and the approver |
| 12b | A stale render result never overwrites the current selection | `shot.current_render_id` · `intent_version` · `auto_select_version` · `video_render_id` **[implemented]** | the whole loop in contract §9.1 | A running → B becomes the current request → B finishes → **A finishing later does not move the pointer**; **input changed while A ran** → A's result is not selected; **replayed completion** does not reselect; **reusing a render already `SUCCEEDED`** → selected inside the dispatch transaction; **current failed** → the previous `video_render_id` **stays**; canary touches nothing; manual selection wins |
| 12c | A replayed operation never hijacks the pointer | `operation_id` + payload hash + result → `video_session_events` with `UNIQUE(session_id, operation_id)` · `shot.intent_version` CAS **[implemented]** | `ShotIntentService` · `SceneClipDispatchService` · manual selection · controller | same `operation_id` twice → exactly **one** state change; manual selection then a stale dispatch retry → **the manual choice survives**; **same ID with a different payload → rejected before replay**; two new operations with the same expected version → one succeeds, one conflicts |
| 12d | The selected result is reconciled | **no status column** — `ShotSelectionReconciler` returns 3 states (contract §9.3); evidence goes to `video_review_decisions` | reconciler shared by readers/checkpoints · `finalCompositionCells` | content/source changes → `stale`; missing legacy evidence → `needs_confirmation`; an existing hard failure is never downgraded merely because the snapshot is absent; current failed but a prior selection is valid → still usable |
| 13 | Environment never falls back silently | screenplay-linked requirement path **[implemented]**; the old `vessel_v2` path stays for legacy | `selectedEnvironmentRequirements()` · `approvedPlate()` | missing or unreadable new environment → **report it missing**, never fall back to milestones |
| 14 | Legacy data stays readable | `shot_id=NULL` · `screenplay_hash=NULL` · `plan_revision=0` | readers | old plans still display; a scene-level image is not treated as a shot keyframe |

---

## 2. Schema change items — implemented migration groups

The former M1-M8 design items are implemented by six consolidated migrations.
They have been exercised on the isolated testing database. Do not run them on
the application database until deployment is explicitly approved.

| # | Table | Change | Risk | Required alongside |
|---|---|---|---|---|
| M1 | `video_shots` | `UNIQUE(session_id, plan_revision, shot_code, kind)` | ✅ code/tests/DB | Implemented in `2026_09_25_000400`; application DB migrated |
| M2 | *(superseded; no migration)* | keep `video_design_images.render_scene_id` | ✅ code/tests | A planned shot is already represented by one `VideoRenderScene`; adding a second `shot_id` owner would duplicate identity |
| M3 | `video_render_scenes` | `+ screenplay_stage_id` `+ screenplay_scene_code` `+ screenplay_hash` | ✅ code/tests | Implemented in `2026_09_25_000300`; `NULL` remains readable as legacy |
| M4 | `video_visual_identities` | `+ subject_key`, change `UNIQUE`, then require non-null | ✅ code/tests/DB | Implemented in `2026_09_25_000100` and tightened by `000600`; all store queries are subject-scoped |
| M5 | `video_projects` | `+ selected_screenplay_stage_id` `+ selected_scene_plan_stage_id` `+ production_selection_version` | ✅ code/tests | Implemented in `2026_09_25_000200` with CAS selection service |
| M6 | *(no migration)* | add `shot_index` to `VideoShot::$fillable`/`$casts` | ✅ code/tests | Implemented and used by scene-plan expansion/order |
| M7 | `video_shots` | `+ current_render_id` `+ intent_version` `+ auto_select_version` | ✅ code/tests | Implemented in `2026_09_25_000400`; `video_render_id` remains the selected result |
| M8 | `video_session_events` | `+ operation_id` · `+ operation_payload_hash` · `+ operation_result_json` · `UNIQUE(session_id, operation_id)` | ✅ code/tests | Implemented in `2026_09_25_000500`; operation event and shot mutation share one transaction |

---

## 3. Order of work

```
1  Specification   complete; these documents define the writer/reader contract
2  Screenplay      complete in code and fixtures; no paid API calls in tests
3  Approval        complete in code: hash-bound decisions, CAS selection,
                   subject-scoped visual identities
4  Breakdown       complete in code: selected screenplay source, 1-10 ordered
                   shots per scene, allowlist and revision preservation
5  Render          complete in code: current versus selected render, operation
                   replay, manual-selection precedence and reconciliation
6  Activation      migrations applied; deliberately approved fake-client flow
                   verified. A paid-provider smoke run remains an operational
                   release action, not an automated test.
```

Runtime activation no longer waits for schema work. Tests do not call a paid
provider.

---

## 4. Appendix — current code audit

This section reflects the implementation after the scene-shot work. Historical
findings that have been resolved are listed as resolved rather than presented as
live defects.

### Three parallel systems

| | Entry point | State |
|---|---|---|
| `VideoPlanningPipeline` | `BuildVideoPlanJob` · `video:build-plan` · `video:benchmark` | wired; Truth Layer → Producer/Director → ScenePlanner → IntentPlanner → TimelinePlanner → RenderPlanAssembler |
| `scene_plan` | the `/scenes/plan` button | **the path that renders today**; `ScenePlanAuthor` + `vessel_v1` milestones → `video_render_scenes` |
| `screenplay` | the screenplay action | connected through hash-bound approval and production selection to `scene_plan` |

### Profiles

```
config video.scene_plan.profiles.yacht   = vessel_v1   planning · 0 environments
config video.environment.profiles.yacht  = vessel_v2   ENVIRONMENT · 5 environments
                                                       20/20 milestones map to one
VideoProjectService:2214-2226   environmentProfile() loads vessel_v2
VideoProjectService:5220        min_scenes GATES THE OUTPUT, not just the load
SceneProfile:287 toPayload()    pushes milestone_groups into the model's user message
```

### Columns used by the new flow

```
video_shots.shot_index          written by SceneShotFactory; used for assembly order
video_shots.plan_revision       written from the selected scene-plan revision
video_render_scenes.revision    identifies the preserved scene-plan revision
video_design_images.render_scene_id scopes a keyframe to one expanded planned shot
```

### Supporting tables

```
video_review_decisions   screenplay approval, manual selection and reconciliation evidence
video_worker_claims      0                a lease shape, NOT a queue
video_session_events     idempotent user operations; UNIQUE(session_id, operation_id)
video_pipeline_failures  0
app/Video/ShotQa/        0 callers        4 services + 3 models + 4 migrations (2026-08-31)
                                          ApprovedShotRevisionService::freeze() — 9 hashes
```

### Resolved defects covered by regression tests

```
scene clip cells             preserve current-request state separately from selected content
finalCompositionCells()      uses only the selected render after reconciliation
SceneShotFactory             keys by plan revision and writes shot_index/plan_revision
markVideoReady()             checks ownership, purpose, current request and auto-select CAS
manual selection             increments intent and revokes completion auto-select authority
DesignImageStore::sameSlot() scopes scene keyframes by render_scene_id
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

### Retired Python Composer/Runner path

```
routes/api.php          no Composer/Runner endpoints; the former 10 paths return 404
                       and are guarded by `VideoApiTokenTest`
```
The owner has decided to **drop Python**. Removing these 10 routes means:
- `video_shots` keeps **one** content writer (`SceneShotFactory`)
- `plan_revision` has **zero** live writers
- the supersede at `VideoSessionService:765` and the `$wouldOrphan` guard die with it

Stage one is complete: routes are gone. Dead controller/service methods remain
for a separate mechanical cleanup after deployment evidence confirms nothing
external still calls them.
`video_sessions` does **not** die with it — `SceneShotFactory::sessionFor()` creates one per project, and `VideoFinal` is looked up by `session_id`.
