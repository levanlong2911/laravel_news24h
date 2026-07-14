# FilmOS Benchmark Scenario Schema — v1

Level A (Pipeline Benchmark) scenario catalog. 16 scenarios, 4 suites × 4.
Scenario files contain **data only** — every rule about how that data is
interpreted lives here, not inside the JSON files.

## Core rules (locked)

1. **Semantic intent only.** Every text field states WHAT happens
   (`goal`, `action`, `ending_frame`, `visual_hint`), never HOW a vendor
   prompt should phrase it. Scenario never leaks prompt language, exactly
   as StructuredPrompt IR never leaks vendor phrasing.
   - Correct: `"goal": "The audience should feel mounting desperation before the throw."`
   - Wrong: `"prompt": "The crowd roars with overwhelming intensity..."`

2. **Authored beats are AUTHORITATIVE** (option c). The runner builds
   GoalNodes from `shots{}` directly — the scenario author IS the
   narrative designer. `facts[]` are context today and become
   derivation-benchmark input when MeaningResolver gains real
   intelligence; authored beats then serve as ground truth to compare
   generated beats against. Neither replaces the other.

3. **Beats, not ordinals.** `shots{}`, `emotion_arc[].at` and
   `scene_nodes{}` are keyed by StoryBeat value (`hook`, `escalation`,
   `reveal`, `payoff`). Ordinal is a Planning concern: the runner assigns
   ordinals following the cinematic order of `StoryBeat::cases()` at
   GoalNode build time. `emotion_arc` additionally allows `baseline`,
   which the runner maps to ordinal −1.

4. **Enum values match code exactly.** The runner calls `::from()` on
   every enum field — zero parsing, zero normalisation. See tables below.

5. **`focus_node`** (in `shots.*.camera`) is a SceneNode id reference for
   the beat's entry in `scene_nodes{}`. Resolution chain:
   `focus_node → scene node → world_object_ref`. Omit when the shot has
   no single focal subject. (Named `focus_node`, not `focus`, so it can
   never be confused with focus mode / depth-of-field semantics.)

6. **`importance`** per beat: `required` | `optional`. The compiler may
   merge / split / insert beats, but a `required` beat can never be
   dropped; an `optional` beat may be dropped or expanded.

## Field reference

| Field | Type | Notes |
|---|---|---|
| `schema_version` | int | `1` for this document |
| `id` | string | equals filename without `.json` |
| `suite` | enum | `camera` \| `emotion` \| `world` \| `motion` |
| `level` | string | `A` (pipeline benchmark) |
| `difficulty` | enum | `easy` \| `medium` \| `hard` \| `extreme` |
| `duration_seconds` | int | target clip length |
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

## Suites and learning dimensions

| Suite | Scenarios | Learns |
|---|---|---|
| camera | nfl_last_second_bomb (medium), subway_crowd_pickpocket (extreme), perfume_macro_droplet (easy), yacht_drone_dive (hard) | lens, shot type, movement, angle, focus |
| emotion | rain_farewell (medium), candlelit_confession (easy), wedding_toast (medium), boxer_final_round (hard) | emotion intensity, hold duration, beat pacing, reaction shot |
| world | glacier_sunrise (easy), night_market (medium), blacksmith_forge (easy), refinery_explosion_escape (extreme) | environment richness, lighting, weather, particles, smoke, fire |
| motion | street_dance_battle (hard), wild_stallion (medium), supercar_chase (hard), horror_hallway (hard) | motion complexity, occlusion, continuity, physics |

Difficulty distribution: easy ×4, medium ×5, hard ×5, extreme ×2.

## Versioning

Bump `schema_version` on any breaking change to field names, enum
domains, or interpretation rules — then migrate all 16 files in the same
commit. Planned future extensions (dialogue, audio, subject graph,
blocking constraints, timing) are additive and stay within v1 until one
of them changes existing semantics.
