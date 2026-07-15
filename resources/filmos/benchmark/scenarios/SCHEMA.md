# FilmOS Benchmark Scenario Schema — v2

Level A (Pipeline Benchmark) scenario catalog. 16 scenarios, 4 suites × 4.
Scenario files contain **data only** — every rule about how that data is
interpreted lives here, not inside the JSON files.

**v2 is an ADDITIVE evolution of v1, not a replacement.** Every v1 file is a
valid v2 file. v2 only adds two OPTIONAL top-level sections — `production` and
`performance` — so the catalog can exercise all six frozen Knowledge domains
(Story, Character, World, Scene, Production, Performance) instead of four. The
flat v1 structure is unchanged; nothing is wrapped or renamed.

## Core rules (locked)

1. **Semantic intent only.** Every text field states WHAT happens
   (`goal`, `action`, `ending_frame`, `visual_hint`, `director_intent`,
   `intent`, cue `description`), never HOW a vendor prompt should phrase it.
   Scenario never leaks prompt language, exactly as StructuredPrompt IR never
   leaks vendor phrasing.
   - Correct: `"goal": "The audience should feel mounting desperation before the throw."`
   - Wrong: `"prompt": "The crowd roars with overwhelming intensity..."`

2. **Authored knowledge is AUTHORITATIVE** (option c). The runner builds the
   NarrativeState from the authored sections directly — the scenario author IS
   the narrative / production / performance designer. `facts[]` are context
   today and become derivation-benchmark input when MeaningResolver gains real
   intelligence; authored knowledge then serves as ground truth to compare
   generated output against. **Same data, two roles:** input to the pipeline
   in Round 1, ground-truth reference once a Planner exists — no format change.

3. **Beats, not ordinals.** `shots{}`, `emotion_arc[].at`, `scene_nodes{}`,
   `performance{}`, and the ordinal-anchored `production` fields
   (`hero_moment.at`, `energy_curve[].at`, `timings[].at`) are all keyed by
   StoryBeat value (`hook`, `escalation`, `reveal`, `payoff`). Ordinal is a
   Planning concern: the runner assigns ordinals following the cinematic order
   of `StoryBeat::cases()` at GoalNode build time, then translates every
   `at: <beat>` into that ordinal when building ProductionPlan / PerformanceDesign.
   `emotion_arc` additionally allows `baseline`, mapped to ordinal −1.

4. **Enum values match code exactly.** The runner calls `::from()` on every
   enum field — zero parsing, zero normalisation. See tables below.

5. **`focus_node`** (in `shots.*.camera`) is a SceneNode id reference for the
   beat's entry in `scene_nodes{}`. Resolution chain:
   `focus_node → scene node → world_object_ref`. Omit when the shot has no
   single focal subject. (Named `focus_node`, not `focus`, so it can never be
   confused with focus mode / depth-of-field semantics.)

6. **`importance`** per beat: `required` | `optional`. The compiler may merge /
   split / insert beats, but a `required` beat can never be dropped; an
   `optional` beat may be dropped or expanded.

7. **`production` and `performance` are OPTIONAL** (v2). A scenario without
   them exercises only Story / World / Character / Scene. **Absent ≠ empty
   stub** — the loader simply skips `planProduction()` / `directPerformance()`
   for that scenario. Never write `"production": {}` to mean "not authored";
   omit the key entirely. This is why v1 files need no migration.

8. **Contract evolution rule.** A scenario MUST declare the highest
   `schema_version` whose fields it actually uses — no higher, no lower.
   Version numbers describe the **JSON contract**, not the maturity of the
   scenario. A file that uses any v2 field (`production` / `performance`)
   declares `schema_version: 2`; a file using only v1 fields declares `1`.
   Consequence: files migrate to v2 **individually, as they are authored** —
   never in lockstep. A `schema_version: 1` file carrying a `production`
   section is a contract error (`ScenarioCatalogTest` will reject it).

## Field reference

| Field | Type | Notes |
|---|---|---|
| `schema_version` | int | `1` if the file uses only v1 fields; `2` if it uses any v2 field (`production`/`performance`). See rule 8 |
| `id` | string | equals filename without `.json` |
| `suite` | enum | `camera` \| `emotion` \| `world` \| `motion` |
| `level` | string | `A` (pipeline benchmark) |
| `difficulty` | enum | `easy` \| `medium` \| `hard` \| `extreme` |
| `duration_seconds` | int | target clip length (whole clip); independent of `production.timings` |
| `primary_learning_dimension` | string | what this scenario exists to teach C.8B |
| `secondary_learning_dimensions` | string[] | supporting dimensions |
| `stress_dimensions` | string[] | what makes this scenario hard for providers |
| `goal` | string | audience-experience objective ("The audience should feel...") |
| `facts[]` | FilmFact[] | ground truth — `category`: `EVIDENCE`\|`RESULT`\|`CONTEXT`; `visual_relevance`: `HIGH`\|`MEDIUM`\|`LOW`; `confidence`: 0.0–1.0 |
| `world_objects[]` | object[] | stable identity anchors (`id`, `type`, `label`, `attributes`) |
| `world_facts` | object | flat key/value world state |
| `characters[]` | object[] | may be empty for character-less scenes (product / pure-world) |
| `emotion_arc` | map | character id → `[{at, state, intensity, cause?}]`; may be empty when `characters` is empty |
| `shots{}` | map | beat → `{importance, action, camera, ending_frame}` |
| `scene_nodes{}` | map | beat → SceneNode[] (`id`, `type`, `label`, `world_object_ref`) |

### v2 optional section — `production` (→ ProductionPlan)

Omit the whole key when not authored. Every sub-field is itself optional.

| Field | Type | Notes |
|---|---|---|
| `production.director_intent` | string | objective only (what the audience must believe/feel); free-text v1-temporary |
| `production.conflicts[]` | object[] | `{description, type}` — forces against the objective |
| `production.motifs[]` | object[] | `{label, importance}` — recurring visual elements |
| `production.constraints[]` | object[] | `{target, rule, mode}` — staging rules |
| `production.hero_moment` | object | `{at: beat, description}` — THE frame the piece builds toward |
| `production.energy_curve[]` | object[] | `{at: beat, value: 0–100, reason?}` — cinematic energy per beat |
| `production.timings[]` | object[] | `{at: beat, duration_seconds}` — per-shot pacing (staging decision, distinct from top-level `duration_seconds`) |

### v2 optional section — `performance` (→ PerformanceDesign)

Omit the whole key when not authored. Keyed `beat → characterId → direction`.

| Field | Type | Notes |
|---|---|---|
| `performance.{beat}.{characterId}.intent` | string | Level 1 semantic acting direction ("suppress fear"); may contradict emotion on purpose |
| `performance.{beat}.{characterId}.motivation` | string? | why — optional |
| `performance.{beat}.{characterId}.cues[]` | object[] | `{description, channel?}` — Level 2 observable behaviors; **array order = temporal order inside the shot** (anti-keyframe: no timestamps) |

## Enum tables (source of truth: `app/Services/AI/FilmOS/Narrative/...`)

| Enum | Values |
|---|---|
| StoryBeat | `hook`, `escalation`, `reveal`, `payoff` (declaration order = cinematic order) |
| ShotType | `establishing`, `wide`, `medium`, `close_up`, `extreme_close_up`, `two_shot`, `insert` |
| CameraAngle | `eye_level`, `high`, `low`, `dutch`, `birds_eye`, `worms_eye`, `over_shoulder` |
| CameraMovement | `static`, `pan`, `tilt`, `tracking`, `dolly`, `zoom`, `handheld` |
| LensType | `wide`, `normal`, `telephoto` |
| EmotionalState | `neutral`, `joy`, `fear`, `anger`, `sadness`, `determination`, `surprise` |
| EmotionIntensity | `subtle`, `moderate`, `intense` |
| SceneNodeType | `camera`, `subject`, `background`, `light` |
| ConflictType | `physical`, `environmental`, `psychological`, `social`, `time` |
| MotifImportance | `primary`, `secondary` |
| ConstraintMode | `never`, `always` |
| PerformanceChannel | `gaze`, `face`, `breath`, `posture`, `hands`, `voice` |

## Suites and learning dimensions

| Suite | Scenarios | Learns |
|---|---|---|
| camera | nfl_last_second_bomb (medium), subway_crowd_pickpocket (extreme), perfume_macro_droplet (easy), yacht_drone_dive (hard) | lens, shot type, movement, angle, focus |
| emotion | rain_farewell (medium), candlelit_confession (easy), wedding_toast (medium), boxer_final_round (hard) | emotion intensity, hold duration, beat pacing, reaction shot |
| world | glacier_sunrise (easy), night_market (medium), blacksmith_forge (easy), refinery_explosion_escape (extreme) | environment richness, lighting, weather, particles, smoke, fire |
| motion | street_dance_battle (hard), wild_stallion (medium), supercar_chase (hard), horror_hallway (hard) | motion complexity, occlusion, continuity, physics |

Difficulty distribution: easy ×4, medium ×5, hard ×5, extreme ×2.

## Versioning

`production` and `performance` were added as OPTIONAL additive sections when
the Production and Performance Knowledge domains were frozen (commits 8b327c6,
0ba841f). v1 files remain valid without them.

Per rule 8, a file's `schema_version` tracks the fields it actually uses, so
adopting v2 is per-file, not lockstep: a scenario flips to `schema_version: 2`
the moment it is authored with `production`/`performance`, while untouched
files stay at `1`. All 16 files currently declare `1` and use no v2 fields.

A BREAKING change (renaming a field, narrowing an enum, changing an
interpretation rule) is different — that DOES force migrating every file in
the same commit and bumping the shared version. Purely additive optional
sections like these two do not.
