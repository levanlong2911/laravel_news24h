# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

Two systems in one Laravel 10 app (PHP 8.1, MySQL, XAMPP on Windows):

1. **News CMS + AI writing pipeline** — crawl keywords → raw articles → Claude (Haiku then Sonnet) rewrite → guarded publish as `Post`.
2. **AI Video "Semantic OS"** (`app/Video/`, active work) — turns an article into a `RenderPlan.json`. It is the **frontend of a compiler**; the Python backend (`media_runtime/`, **a separate repo — not in this tree**) consumes RenderPlan and produces video.

The `production-grade` branch is the live branch for video work. `docs/video/ARCHITECTURE.md` is the **single source of truth** for the video pipeline — architecture changes must edit that doc *before* the code. `docs/video/ADR-pipeline-v1.md` holds the render-side ADR (motion spec layers, cost model). Both docs are in Vietnamese, as are most code comments; match that language when editing them.

## Commands

```bash
php vendor/bin/phpunit --testsuite=Video            # video pipeline suite (382 tests, ~2s, no DB needed)
php vendor/bin/phpunit --testsuite=Unit             # / --testsuite=Feature
php vendor/bin/phpunit tests/Video/Story/StoryPlannerTest.php          # single file
php vendor/bin/phpunit --filter test_producer_never_changes_acts_or_scenes   # single test
php artisan test                                     # same via Laravel wrapper

php vendor/bin/pint                                  # formatter (no pint.json — Laravel preset)
npm run dev / npm run build                          # Vite (CKEditor + admin assets)

php artisan queue:work                               # only ProcessKeywordJob is ever dispatched (see note below)
php artisan news:dispatch [--keyword=UUID]           # dispatch crawling for active keywords
php artisan video:benchmark --sample=mixed10 --extractor=fake    # $0 harness check
php artisan video:benchmark --sample=mixed10 --extractor=claude  # REAL, costs money — needs approval

php artisan video:build-plan --session=<code>        # manual fallback if the 🎬 Tạo Video spawn failed — same pipeline, real money
php artisan video:reset-planning-claim --session=<code>  # only after confirming the old video:build-plan process is truly dead
```

`--extractor=fake` is free and deterministic; `claude` spends real money. Never run a paid command (or any render) without showing the plan and getting explicit approval first.

## Video pipeline architecture (`app/Video/`)

Entry point: `Pipeline/VideoPlanningPipeline::plan(RawArticle, RenderPlanMeta, targetSeconds)` → `array` ready to `json_encode` and validate against `contracts/renderplan/v1.0/schema.json`. Build it via `VideoPipelineFactory` (injected — instance methods, not static: `$factory->claude($llm, $factory->productionPolicies())`) — the factory exists to guarantee **one** `EditorialInterpreter` instance is shared by the pipeline and the assembler (two instances = policies silently apply in `candidatesFor()` but `continuity.prohibitions` stays empty).

Flow:

```
Truth Layer (evidence required, deterministic gate)
  ArticleNormalizer → EvidenceIndex → Extractor (LLM = hypothesis generator)
  → CandidateWorldGraph → EvidenceGatekeeper → VerifiedWorldGraph
Planning Layer (no evidence needed, creative allowed)
  StoryPlanner → ScenePlanner → IntentPlanner → TimelinePlanner
  Producer (LLM, parallel branch) ┐
  Director (LLM, picks from EditorialInterpreter::candidatesFor()) ┤
  EditorialInterpreter (taste, prohibitions, fill-missing) ┘
  → RenderPlanAssembler → RenderPlan.json   ← immutable from here
```

The invariants below are enforced by `tests/Video/Architecture/ArchitectureTest.php` (PHP-tokenizer grep over `app/Video/`, comments excluded). Breaking one turns CI red; do not weaken the test to make code pass.

- **Laravel does not know prompt language exists.** Banned in `app/Video/`: `prompt`, `cinematic`, `photorealistic`, `8k`, `mm lens`, … Prompt compiling lives in Python.
- **No render technique or provider names.** Banned: `kling`, `flux`, `veo`, `runway`, `ffmpeg`, `ken burns`, `content_type`, `image_to_video`. Laravel emits *intent* (`motion_intent: NONE|LOW|HIGH`); Python chooses the implementation.
- **No domain branching.** Banned: `yacht`, `feadship`, `$topic ===`, `switch ($domain)`. There is no `YachtPlanner`. Domain knowledge exists only as **data** (`config/video.php` `editorial_policies`, Knowledge libraries) — never as a code branch. `Act = a node or edge of the World Graph` (`act.source: ENTITY|EVENT|RELATION`), which is why one planner covers every topic.
- **Gatekeeper is 100% deterministic.** `app/Video/Gatekeeper/` may not reference `claude`/`llm`/`Http::`/`rand`/`now()`. The LLM proposes candidates with a verbatim `evidence_quote` and **never an offset**; the Gatekeeper `find()`s the quote itself. No quote found → reject. `confidence` is observability only, never a decision input.
- **Planning Layer cannot touch Truth provenance.** `Story/ Scene/ Intent/ Timeline/ Editorial/` may not reference `->evidence`, `->quote`, `->offset`, `EvidenceIndex`, `RawArticle`, or the `Evidence`/`Article`/`Extraction` namespaces — they read `VerifiedWorldGraph` only.
- **The word `derived` is banned** in code; use `NORMALIZED_VALUE` (it is the provenance level for pure functions of a single span, e.g. `"101 metres"` → `101.0`; anything needing outside knowledge is `INFERRED` → reject).
- **Evidence never crosses the boundary.** RenderPlan is post-verification: no evidence/quote/span/offset/provenance/confidence fields. Debug via `plan_id` back into Laravel.
- **Editorial is read-only over `VerifiedWorldGraph`** and currently guaranteed by the type system (`Entity::$attributes` readonly, no setters). Preserve that immutability when editing `app/Video/World/`. Editorial may add/beautify/emphasize; never create, edit, or infer a fact.
- **RenderPlan v1.0 is frozen and immutable** (§14). Additive only, and only when genuinely required. Optimization/normalization/provider adaptation belongs to Python's VideoIR, not here.

Two rules govern whether new code may exist at all:

- **Rule 0 — every abstraction must pay rent** with duplication that *already exists*, not predicted duplication. Layers carry a Maturity label (Stable / **Reserved** = name+position agreed but no code / Future). `NarrativeGraph`, `RenderIR`, the mechanism Strategy selector are Reserved: do not implement them.
- **Decision Placement Principle (§18.7)** — *Subjective* (needs judgment) → LLM (Producer, Director); *Objective* (rule/world-knowledge determined) → deterministic Rule Engine (`IntentPlanner` for camera, `EditorialInterpreter::prohibitionsFor()`, candidate expansion); *Syntax* (form only) → Python compiler. Camera is Objective — the Director must not decide it. `StoryPlanner` ranking is Objective (graph centrality) — Producer must not touch its signature. §18.6 lists proposals already rejected (LLM cinematographer, LLM scene planner, separate Reviewer/Researcher agents, `semantic_density`, renaming `scene.camera/aesthetic`); re-read it before proposing an agent or a rename.

`scene` fields split by knowledge type (§13): `aesthetic{}` is **required** editorial taste (fill a default when nothing special); `world{}` is **optional** world fact from Truth — when Truth is silent, **omit it**, never default or infer, and let the provider's own priors fill in.

## LLM cost safety

`app/Video/Llm/` wraps every paid call **of the video pipeline**: `GatedLlmClient` consults an `ApprovalGate`, and the system default is `DenyByDefaultGate` (refuses everything) — spending requires explicitly passing `CostCeilingGate`.

**The gate does NOT cover the CMS writing path.** `ArticlePipelineService` (:52 haiku, :88 sonnet, :103 sonnet-retry) and `HookEngine` (:207 haiku) call `ClaudeWriterService::generate()` directly, with no gate and no ceiling — `config('video.llm_cost_ceiling_usd')` has no effect there. Only the three video callers (`ClaudeExtractor`, `ClaudeProducer`, `ClaudeDirector`, all defaulting to `haiku`) go through `GatedLlmClient`. Every path ends at the one HTTP call in `ClaudeWriterService::generate()`. `CostAccumulatingLlmClient` totals spend. `ClaudeWriterAdapter` bridges to `Services/Admin/ClaudeWriterService`, which holds the model ids, per-1M-token pricing, retry/RPM limits, and logs to `ClaudeUsageLog`. `CLAUDE_API_KEY` comes from `config/services.php`.

## Laravel ↔ Python integration

**Laravel triggers, work runs in the background** (§18.25, extended by §18.30 on 2026-08-12). All three trigger points spawn a detached process and return immediately — the HTTP request never waits:

| Click | Laravel does (fast, in-request) | then spawns | runs in |
|---|---|---|---|
| 🎬 Tạo Video | creates an empty `VideoSession`, `status=planning` | `php artisan video:build-plan --session=<code>` — runs the Claude pipeline (11+ calls, 25-90s, real money), then saves `renderplan_json` and moves to `composing` | this same Laravel repo |
| (the command above, on success) | — | `tools/session_runner.py --session=<code>` — compiles prompts, seconds, $0 | the Python repo |
| 🎬 Render các shot đã duyệt | `approved → queued`, `status=rendering` | `tools/render_queued_shots.py --session=<code>` — calls Veo, 6-18 min, real money | the Python repo |

`video:build-plan` deliberately does **not** go through Laravel's queue (`Job::dispatch()`): `QUEUE_CONNECTION=sync` on this machine and no `queue:work` process is running (verified via the `jobs` table — 21 `ProcessKeywordJob` rows sitting since 2026-04-10 with `attempts=0`), so a dispatched job would either run synchronously or rot forever. `PythonRunner::spawnArtisan()` reuses the exact same detached-process mechanism as the Python triggers, just pointed at `php artisan` instead of `python`.

Spawning is confined to one class (`PythonRunner`) so `grep` finds every trigger in one place — its name stayed Python-specific even after gaining `spawnArtisan()`, on purpose, to avoid a repo-wide rename for a class only one file injects. **A failed spawn is never fatal**: the session stays usable and the UI shows the manual fallback command — the scripts still run standalone.

The admin who clicked is recorded on `video_sessions.requested_by_admin_id` **at creation time** (inside the HTTP request, where `auth()->id()` is trustworthy) — never passed as a CLI flag, since anyone can run the command by hand with an arbitrary `--admin=`. The background command re-authenticates via `Auth::loginUsingId()` reading only that stored id, so `VideoRenderPlanService::recordUsage()` still attributes cost correctly. If the stored admin no longer exists, the command fails closed (`status=failed`) **before** calling Claude.

`runVideoPlanningPipeline()` claims the session (`planning_claimed_at` + a `planning_claim_token`) before calling Claude, so two concurrent runs of the same `--session=` can't both spend money. The claim **never auto-expires** — a single Claude call can legitimately take up to ~10 minutes under retries (`ClaudeWriterService::MAX_RETRIES=5` × 60s timeout + backoff), so a time-based lease isn't safe. A stuck claim only clears via `php artisan video:reset-planning-claim --session=<code>`, and the claim token is re-checked (`UPDATE ... WHERE planning_claim_token=?`) at the moment the result is saved, so a worker that lost ownership mid-run can't overwrite whoever claimed it after.

The HTTP API stays exactly as it was (`routes/api.php`, all guarded by the `X-Video-Token` header matching `VIDEO_API_TOKEN`) — it is how Python reports *back*, and it is also the migration path if Python ever moves to another machine (then go back to polling):

- `POST /api/render-plans` — Python pushes a session + shots (`storeFromPython`)
- `GET /api/video-sessions/composing` · `GET /api/video-shots/queued` — still work; used by the standalone/manual runs
- `PATCH /api/video-shots/{shotId}/result` — runner reports artifact + cost, **once per shot**. That per-shot reporting is what makes a long render crash-safe: finished clips are already saved, and re-clicking Render only picks up what is still `queued`.

Session lifecycle: `draft → planning → composing → reviewing → rendering → done | failed`. Shot lifecycle (the approval gate) is separate: `draft → approved | needs_revision | rejected`, then `approved → queued → rendered | failed`. The 🎬 button calls `VideoSessionService::startVideoPlanning()` (idempotent — locks the `Article` row, refuses to create a second session while a non-terminal one exists for that article); the actual pipeline run and `renderplan_json` save happen in `runVideoPlanningPipeline()`, called from the spawned `video:build-plan` command, not from the controller.

## CMS side conventions

- Controllers stay thin; logic lives in `app/Services/Admin/*Service.php`; data access goes through `app/Repositories/Interfaces/*` bound to `Repositories/Eloquent/*` in `Providers/RepositoryServiceProvider.php`.
- `ArticlePipelineService::run()` is the single entry point for AI writing: clean → Haiku → `HookEngine` → `PromptGuard` → Sonnet (+1 retry on parse failure) → `PostGuard` → `PipelineResult`. The caller owns persistence, status updates, and `FeedbackService`. Prompt/guard failures throw (`PromptGuardException`, `PreGuardException`).
- **AI writing runs synchronously from the controller, not from a queue** — `ArticleController::sendToClaude()` / `synthesize()` call the service directly. `ProcessKeywordJob` (the only job ever dispatched) just crawls via `FetchKeywordNewsService::fetch()` and chains nothing, so crawl → write is **not** automated end to end; a human clicks. Verified 2026-07-30 against the `jobs` table (21 rows, all `ProcessKeywordJob`) and full git history.
- `WriteArticleJob` is **dead code, deliberately so** — it wraps `ArticlePipelineService` with PreGuard → slot reservation → PostGuard → Post. Four `WriteArticleJob::dispatch()` sites were added in `0a6c927` and removed together in `b7ffde0` ("update code auto news v7"); the reason was never recorded. Kept because that orchestration does not exist on the controller path — wire it back if you want automated writing, don't half-use it. (`WritePostFromArticleJob` was a second, older copy that was **never dispatched once** in the whole history and hard-coded its prompts instead of using `PromptBuilderService` — deleted 2026-07-30.)
- Prompt templates are versioned in the DB (`PromptFramework`, `PromptVersion`, `PromptMetric`, `CategoryContext`) and assembled by `PromptBuilderService` — edit data, not prompt strings in code.
- Multi-site: the `DomainContext` middleware scopes requests by domain; the video API routes deliberately opt out of it.

## Working style in this repo

- Evidence over architecture: this project has repeatedly thrown out designs that looked right but had not been validated by a real render or a real Claude run. Prefer validating with a cheap real run over adding a layer.
- Never fabricate visual detail in a plan or prompt — missing detail is usually already in `facts[].visual_hint` or `VerifiedWorldGraph` attributes.
- Explain the purpose of each command before running it (including free ones), batch approval requests, and wait for an ok.
