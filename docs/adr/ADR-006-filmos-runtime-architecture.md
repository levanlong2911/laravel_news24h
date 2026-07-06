# ADR-006: FilmOS Runtime Architecture

**Status:** Proposed  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-001, ADR-002, ADR-003, ADR-004, ADR-005

---

## Context

ADR-001 through ADR-005 define a solid compiler core (AFOS), a filmmaking model
(FilmOS Core), extended engines (VisualLanguage, Acting, Motion, Editing, Memory),
an event bus, and a persistence model.

What is still missing is the **runtime intelligence** layer — the set of systems
that make FilmOS behave like an intelligent operating system rather than a
sophisticated pipeline:

1. **SemanticGraph** — the system has no semantic understanding of what is happening
   in the story. "John saves Mary" should tell every engine that John=Hero, Mary=Victim,
   the gun=threat, the door=obstacle. Right now, each engine infers this independently.

2. **WorldStateEngine** — the system tracks character state and asset instances
   but not the state of the world itself. A door goes from closed → open → broken.
   A building goes from intact → on fire → collapsed. No engine currently tracks this.

3. **DirectorOS** — the current pipeline has no layer that makes artistic decisions
   about shot selection, blocking, camera approach, and shot order. These are director
   decisions, not planner decisions.

4. **EditingOS as active intelligence** — the current EditingOS (ADR-003 Amendment F)
   is primarily an EDL exporter. A real editing AI makes active decisions:
   recommend cuts, suggest shot replacement, adjust pacing, insert reaction shots.

5. **Film Knowledge Base** — VisualMemory tracks visual consistency within a production.
   But across thousands of productions, the system accumulates knowledge about what
   works: villain lighting, hero shot angles, motif patterns. This knowledge should
   be reusable.

6. **BudgetEngine + ProviderSelector** — the system has no cost optimization.
   An establishing shot does not need Veo Ultra. A hero's close-up reaction may justify it.

7. **QualityEngine** — after rendering, no system evaluates quality and decides
   whether to retry, switch backend, or refine the prompt.

8. **Prompt Intelligence** — after thousands of productions, the system should
   learn which prompts work for which domains and emotions.

9. **Asset Dependency Graph** — changing a character's hat should invalidate
   all downstream outputs (thumbnails, continuity frames, videos, posters).

10. **Plugin Architecture** — the system needs official extension points so that
    Kling, Veo, Runway, Sora, ElevenLabs, Whisper, FinalCut can be integrated
    without modifying the core.

---

## Decision

Define a **Runtime Architecture** layer sitting above FilmOS Core (ADR-002) and
FilmOS Extended Engines (ADR-003). This layer contains ten subsystems organized
into three groups:

**Semantic Intelligence** — understands meaning  
**Production Intelligence** — makes runtime decisions  
**Platform Intelligence** — learns, optimizes, extends

---

## Group 1: Semantic Intelligence

### Subsystem 1A: SemanticGraph

> "The canonical representation of what the film is about."

SemanticGraph is the semantic backbone that every other engine reads.
It answers: who is this character narratively? what are their objectives?
what is the conflict? what does this object mean?

```php
namespace App\Services\AI\FilmOS\Semantic;

/**
 * Semantic representation of the entire production's narrative.
 * Built once from StoryDTO/SceneGraph; read by every engine.
 *
 * This is NOT a plot summary — it is structured narrative semantics:
 * roles, relationships, objectives, conflicts, payoffs, foreshadowing.
 */
final class SemanticGraph
{
    public readonly string             $productionId;
    /** @var CharacterRole[] — narrative role per character */
    public readonly array              $characterRoles;
    /** @var NarrativeRelationship[] — relationships between characters */
    public readonly array              $relationships;
    /** @var NarrativeObjective[] — what each character wants */
    public readonly array              $objectives;
    /** @var ConflictNode[] — active conflicts */
    public readonly array              $conflicts;
    /** @var Payoff[] — story payoffs (promise → delivery) */
    public readonly array              $payoffs;
    /** @var ForeshadowElement[] — visual/thematic foreshadowing */
    public readonly array              $foreshadowing;
    /** @var SymbolRegistry — recurring symbols and their meanings */
    public readonly SymbolRegistry     $symbols;
    /** @var ThemeRegistry — thematic underpinnings */
    public readonly ThemeRegistry      $themes;
}

final class CharacterRole
{
    public readonly string           $characterId;
    public readonly NarrativeRole    $role;       // HERO, VILLAIN, MENTOR, VICTIM, ALLY...
    public readonly float            $importance; // 0.0–1.0 (protagonist vs. background)
    public readonly string           $archetype;  // "reluctant hero", "tragic villain"...
    public readonly array            $traits;     // ["determined", "protective", "flawed"]
    public readonly ?string          $foilOf;     // characterId of their narrative foil
}

final class NarrativeRelationship
{
    public readonly string              $fromCharId;
    public readonly string              $toCharId;
    public readonly RelationshipType    $type;    // ALLY, ENEMY, MENTOR, ROMANTIC, RIVAL...
    public readonly float               $tension;  // 0.0 (harmony) → 1.0 (maximum conflict)
    public readonly bool                $isHidden; // unrevealed to viewer yet
    public readonly ?string             $revealedAtShotId;
}

final class ConflictNode
{
    public readonly string          $conflictId;
    public readonly ConflictType    $type;     // EXTERNAL, INTERNAL, SOCIAL, ENVIRONMENTAL
    public readonly string          $description;
    public readonly string          $characterId;   // who faces this conflict
    public readonly ?string         $antagonistId;  // who/what opposes
    public readonly float           $intensity;     // 0.0–1.0
    public readonly ConflictStatus  $status;        // UNRESOLVED, ESCALATING, RESOLVED
}

final class Payoff
{
    public readonly string    $promise;       // what was set up (e.g. "woman trusts hotel")
    public readonly string    $delivery;      // what delivers (e.g. "underwear in kettle")
    public readonly string    $promiseShotId;
    public readonly string    $deliveryShotId;
    public readonly PayoffType $type;         // REVELATION, IRONY, CALLBACK, SUBVERSION
}

final class ForeshadowElement
{
    public readonly string    $assetId;       // "kettle_hotel_01" — the foreshadowed object
    public readonly string    $meaning;       // "violation of private space"
    public readonly string    $firstShotId;
    public readonly string    $payoffShotId;
    public readonly bool      $isVisual;      // visual or thematic foreshadowing
}
```

**Integration:** SemanticGraph is built by `SemanticGraphBuilder` from `StoryDTO`
and injected into `PlanningContext.visual`. Every engine reads it:
- `DirectorOS` reads `ConflictNode.intensity` → handheld vs. dolly decision
- `ActingEngine` reads `CharacterRole.traits` → acting style selection
- `VisualLanguageEngine` reads `ForeshadowElement` → framing the object meaningfully
- `EditingOS` reads `Payoff` → knows when to cut to reaction

### Subsystem 1B: WorldStateEngine

> "The world remembers what has happened to it."

Different from `WorldModel` (static definitions) and `CharacterState` (character dynamics),
`WorldStateEngine` tracks the physical state of the environment across shots.

```php
namespace App\Services\AI\FilmOS\WorldState;

final class WorldStateEngine
{
    /**
     * Apply an event to the current world state.
     * StateTransition: CLOSED → OPEN (door opened by character action)
     */
    public function applyEvent(WorldEvent $event): WorldState { ... }

    /**
     * Get the world state at a specific shot.
     */
    public function stateAt(string $productionId, string $shotId): WorldState { ... }

    /**
     * Validate that a shot's described world state is consistent
     * with the engine's recorded state history.
     */
    public function validateConsistency(ShotNode $shot, WorldState $described): WorldStateReport { ... }

    /**
     * Full state history for audit/debug.
     */
    public function history(string $productionId): StateHistory { ... }
}

final class WorldState
{
    /** @var AssetStateEntry[] — state of every tracked asset */
    public readonly array   $assetStates;
    /** @var EnvironmentState[] — state of environments (rooms, buildings) */
    public readonly array   $environmentStates;
    /** @var WeatherState — current weather */
    public readonly WeatherState $weather;
    /** @var TimeOfDay — current time */
    public readonly TimeOfDay $timeOfDay;
    /** @var LightingState — current lighting condition */
    public readonly LightingState $lighting;
}

final class AssetStateEntry
{
    public readonly string      $assetId;
    public readonly AssetPhysicalState $state;   // INTACT, OPEN, CLOSED, BROKEN, BURNING, DESTROYED
    public readonly string      $changedAtShotId;
    public readonly ?string     $changedByCharId; // who caused the state change
}

final class WorldEvent
{
    public readonly string          $shotId;
    public readonly WorldEventType  $type;     // ASSET_STATE_CHANGE, LIGHTING_CHANGE,
                                               // TIME_ADVANCE, WEATHER_CHANGE, ENVIRONMENT_DAMAGED
    public readonly array           $payload;  // event-specific data
}

// Enum: AssetPhysicalState
enum AssetPhysicalState {
    case INTACT;
    case OPEN;
    case CLOSED;
    case UNLOCKED;
    case LOCKED;
    case BROKEN;
    case BURNING;
    case DESTROYED;
    case MOVED;
    case EMPTY;
    case FILLED;
}
```

**Integration:** `WorldStateEngine.validateConsistency()` is called by `ConstraintEngine`
as a new `WorldStateConstraint`. The engine knows "the door was closed in Shot 3, you
cannot show it open in Shot 5 without a transition shot."

---

## Group 2: Production Intelligence

### Subsystem 2A: DirectorOS

> "The artistic decision-maker between Story and Shot."

`DirectorOS` sits between `SceneShotPlanner` and `PlanningContext`. It makes the
artistic decisions that a human director would make before calling "action":
shot selection, camera approach, blocking, shot order, emotional curve.

```php
namespace App\Services\AI\FilmOS\Director;

/**
 * The artistic brain of FilmOS.
 * Input: SceneGraph + SemanticGraph + StyleBible
 * Output: DirectorPlan (per scene)
 */
final class DirectorOS
{
    public function plan(
        SceneGraph            $scenes,
        SemanticGraph         $semantics,
        FrozenProductionBible $bible,
    ): DirectorPlan { ... }
}

final class DirectorPlan
{
    /** @var SceneDirectorPlan[] — one plan per scene */
    public readonly array              $scenePlans;
    public readonly EmotionCurve       $emotionCurve;   // whole-production emotional arc
    public readonly PacingPlan         $pacing;         // overall rhythm decisions
    public readonly array              $signatureShots; // shots the director "cares about most"
}

final class SceneDirectorPlan
{
    public readonly string              $sceneId;
    /** @var ShotDirectorDecision[] */
    public readonly array               $shotDecisions;
    public readonly BlockingPlan        $blocking;      // character positions + movement
    public readonly ShotOrderPlan       $shotOrder;     // which shot should come first
    public readonly EmotionCurve        $sceneArc;
}

final class ShotDirectorDecision
{
    public readonly string              $shotId;
    public readonly CameraApproach      $approach;      // INTIMATE, OBSERVATIONAL, DRAMATIC...
    public readonly bool                $preferHandheld;
    public readonly bool                $isLongTake;    // director wants to avoid cuts
    public readonly CameraMovementType  $preferredMove; // from AFOS Types
    public readonly FramingType         $preferredFraming;
    public readonly DirectorPriority    $priority;      // CRITICAL, IMPORTANT, STANDARD, FILLER
    public readonly ?string             $directorNote;  // "hold on her reaction — do not cut"
}

final class BlockingPlan
{
    /** @var CharacterPosition[] — where characters stand/move */
    public readonly array $positions;
    /** @var MovementBeat[] — character movements during the shot */
    public readonly array $movements;
    public readonly array $eyeLines;   // where each character looks
}

final class EmotionCurve
{
    /** @var EmotionPoint[] — emotion value at each shot */
    public readonly array $points;

    public function peakAt(): string   { ... }  // shotId of emotional peak
    public function valleyAt(): string { ... }  // shotId of emotional valley
    public function tendency(): string { ... }  // "rising", "falling", "arc", "flat"
}
```

**DirectorDecisionRules** — how DirectorOS makes decisions:

```
ConflictNode.intensity > 0.8 → ShotDirectorDecision.preferHandheld = true
CharacterRole = VILLAIN → CameraApproach = LOW_ANGLE + OBSERVATIONAL
Payoff shot → DirectorPriority = CRITICAL + isLongTake = true
ForeshadowElement present → ShotDirectorDecision.preferredFraming = DETAIL
EmotionCurve.peak → FramingType = CLOSEUP
```

### Subsystem 2B: EditingOS as Active Intelligence

> "EditingOS recommends, not just exports."

The original EditingOS (Amendment F) produces EDL and exports. Now it gains an
intelligence layer that actively evaluates the shot sequence and makes recommendations.

```php
namespace App\Services\AI\EditingOS\Intelligence;

final class EditingIntelligence
{
    /**
     * Analyze the current shot sequence and produce recommendations.
     * These are suggestions — the system can accept, override, or ignore.
     */
    public function analyze(
        EditDecisionList  $currentEDL,
        DirectorPlan      $directorPlan,
        SemanticGraph     $semantics,
    ): EditingRecommendations { ... }
}

final class EditingRecommendations
{
    /** @var EditingRecommendation[] */
    public readonly array $recommendations;

    public function hasHighPriority(): bool { ... }
    public function apply(EditDecisionList $edl): EditDecisionList { ... }
}

final class EditingRecommendation
{
    public readonly RecommendationType $type;
    public readonly string             $shotId;
    public readonly float              $confidence;     // 0.0–1.0
    public readonly string             $reason;
    public readonly ?array             $suggestedChange; // what to do
}

enum RecommendationType {
    case CUT_TOO_LONG;           // shot duration exceeds narrative need
    case CUT_TOO_SHORT;          // cut before emotion peaks
    case MISSING_REACTION_SHOT;  // no reaction to a key action
    case PACING_INCONSISTENCY;   // sudden tempo change without payoff
    case MATCH_CUT_OPPORTUNITY;  // two shots could be match-cut for impact
    case REPLACE_SHOT;           // this shot is weaker than alternatives
    case REORDER_SHOTS;          // different shot order would improve flow
    case INSERT_PAUSE;           // add static beat before payoff
    case MUSIC_SYNC_OPPORTUNITY; // cut could align with music beat
    case EMPHASIS_NEEDED;        // key moment needs longer hold
}
```

### Subsystem 2C: QualityEngine

> "Evaluate what was rendered. Decide what to do next."

After `VideoRendered` event fires, `QualityEngine` evaluates the output and decides
whether to accept, retry, switch backend, or refine the prompt.

```php
namespace App\Services\AI\FilmOS\Quality;

final class QualityEngine
{
    /**
     * Evaluate a rendered video against its expected output.
     *
     * @param string       $videoUrl     rendered video URL
     * @param PromptIRSnapshot $snapshot  what was intended
     * @param RenderContext    $context   visual expectations
     */
    public function evaluate(
        string            $videoUrl,
        PromptIRSnapshot  $snapshot,
        RenderContext     $context,
    ): QualityReport { ... }

    /**
     * Based on quality report, decide the next action.
     */
    public function decide(QualityReport $report): QualityDecision { ... }
}

final class QualityReport
{
    public readonly float  $overallScore;      // 0.0–1.0

    // Per-dimension scores
    public readonly float  $sharpness;         // blur / motion blur
    public readonly float  $artifactScore;     // compression artifacts, glitches
    public readonly float  $faceQuality;       // face coherence (landmarks, expression)
    public readonly float  $handQuality;       // hand coherence (notorious AI weakness)
    public readonly float  $lightingMatch;     // vs. expected lighting
    public readonly float  $compositionMatch;  // vs. expected framing
    public readonly float  $continuityScore;   // vs. CharacterState / WorldState
    public readonly float  $promptAdherence;   // how well prompt was followed
    public readonly float  $emotionMatch;      // expected emotion vs. detected
    public readonly float  $identityScore;     // character looks like CharacterDefinition

    public readonly array  $failures;          // QualityFailure[]
}

final class QualityDecision
{
    public readonly QualityAction  $action;
    public readonly ?string        $fallbackBackendId;
    public readonly ?string        $promptRefinementHint;
    public readonly float          $retryPriority;
}

enum QualityAction {
    case ACCEPT;            // score meets threshold, emit ShotAccepted
    case RETRY_SAME;        // retry with same backend + prompt (transient failure)
    case RETRY_REFINED;     // retry with prompt refinement hint
    case SWITCH_BACKEND;    // switch to fallbackBackendId
    case ESCALATE;          // score too low even after retries → human review
}
```

**Quality thresholds by shot priority (from DirectorOS):**

| Shot Priority | Accept threshold | Max retries | On failure |
|--------------|-----------------|-------------|------------|
| CRITICAL | 0.85 | 5 | ESCALATE |
| IMPORTANT | 0.75 | 3 | SWITCH_BACKEND |
| STANDARD | 0.65 | 2 | RETRY_SAME |
| FILLER | 0.50 | 1 | ACCEPT anyway |

**Integration:** `QualityEngine` is called in `OnVideoRendered` listener (ADR-004).
If `decision.action != ACCEPT`, it emits `ShotQualityFailed` event which triggers
retry logic with the appropriate action.

### Subsystem 2D: BudgetEngine + ProviderSelector

> "Not every shot needs the same quality — or costs the same."

```php
namespace App\Services\AI\FilmOS\Budget;

final class BudgetEngine
{
    /**
     * Allocate budget across all shots based on their director priority.
     * Higher-priority shots get more expensive backends.
     */
    public function allocate(
        DirectorPlan    $directorPlan,
        ProductionBudget $budget,
        ProviderCatalog  $catalog,
    ): BudgetAllocation { ... }
}

final class ProductionBudget
{
    public readonly float $totalUsd;
    public readonly float $maxPerShotUsd;
    public readonly float $targetCostUsd;       // soft target
    public readonly float $qualityBias;         // 0.0=cost-first, 1.0=quality-first
}

final class BudgetAllocation
{
    /** @var ShotBudget[] — one per shot */
    public readonly array $shotBudgets;
    public readonly float $estimatedTotalUsd;
    public readonly float $estimatedTotalSec;
}

final class ShotBudget
{
    public readonly string         $shotId;
    public readonly string         $assignedBackendId;   // "kling_v1.6" | "veo_ultra" | "runway_gen3"
    public readonly float          $maxCostUsd;
    public readonly int            $maxLatencySec;
    public readonly ?string        $fallbackBackendId;   // if primary fails cost/quality check
}

final class ProviderSelector
{
    /**
     * Select the best backend for a shot given its budget and requirements.
     *
     * Examples:
     *   FILLER + $0.10 budget   → kling_lite
     *   IMPORTANT + $0.50       → kling_v1.6
     *   CRITICAL + $2.00        → veo_ultra
     *   CROWD_SCENE + $0.30     → runway_gen3 (good at crowds)
     *   WATER_MOTION + $1.00    → veo_standard (best water physics)
     */
    public function select(
        ShotNode           $shot,
        ShotBudget         $budget,
        ProviderCatalog    $catalog,
        SemanticGraph      $semantics,
    ): string { ... }   // returns backendId
}

final class ProviderCatalog
{
    /** @var ProviderSpec[] — all registered providers with capabilities + pricing */
    public readonly array $providers;

    public function bestFor(string $capability, float $maxUsd): ProviderSpec { ... }
    public function cheapestAbove(float $minQuality): ProviderSpec { ... }
}

final class ProviderSpec
{
    public readonly string  $backendId;          // "veo_ultra"
    public readonly float   $costPerSecond;       // USD
    public readonly float   $avgLatencySec;
    public readonly float   $qualityScore;        // 0.0–1.0 (benchmark-derived)
    public readonly array   $strengths;           // ["photorealistic", "water", "crowds"]
    public readonly array   $weaknesses;          // ["hands", "text", "fast_motion"]
}
```

---

## Group 3: Platform Intelligence

### Subsystem 3A: Film Knowledge Base

> "VisualMemory within a production. Film Knowledge Base across all productions."

```php
namespace App\Services\AI\FilmOS\Knowledge;

/**
 * Cross-production knowledge store.
 * VisualMemory = within one production (per-shot consistency).
 * FilmKnowledgeBase = across all productions (patterns, styles, what works).
 */
final class FilmKnowledgeBase
{
    public readonly StyleMemory    $styleMemory;    // "villain uses 35mm low-key"
    public readonly DirectorMemory $directorMemory; // patterns per director style
    public readonly MotifLibrary   $motifLibrary;   // recurring visual symbols
    public readonly CallbackLibrary $callbacks;     // promise → delivery patterns
    public readonly DomainLibrary  $domainLibrary;  // what works per domain
}

final class StyleMemory
{
    /**
     * Learned associations: character archetype → visual treatment.
     * Example: VILLAIN → {lens: 35mm, lighting: low_key, angle: low}
     */
    public function treatmentFor(NarrativeRole $role): array { ... }
    public function record(NarrativeRole $role, array $visualTreatment, float $score): void { ... }
}

final class MotifLibrary
{
    /**
     * Visual symbols with accumulated meanings.
     * Example: "kettle" → appeared 43 times → 89% associated with "domestic violation"
     */
    public function symbolsFor(string $meaning): MotifEntry[] { ... }
    public function meaningOf(string $assetType): MotifEntry[] { ... }
}

final class CallbackLibrary
{
    /**
     * Foreshadow → payoff patterns that tested well.
     * Example: "show object closed (shot N) → reveal open (shot N+X)" = high tension
     */
    public function patternsFor(PayoffType $type): CallbackPattern[] { ... }
    public function record(ForeshadowElement $setup, Payoff $payoff, float $audienceScore): void { ... }
}

final class DomainLibrary
{
    /**
     * What works per domain (automotive, luxury_villa, sports...).
     * Example: luxury_villa → slow crane shots score 23% higher than static
     */
    public function insightsFor(Domain $domain): DomainInsight[] { ... }
    public function topCameraMovesFor(Domain $domain): array { ... }
    public function topEmotionsFor(Domain $domain): array { ... }
}
```

### Subsystem 3B: Prompt Intelligence

> "Learn which prompts work. Improve automatically."

```php
namespace App\Services\AI\FilmOS\PromptIntelligence;

final class PromptIntelligence
{
    /**
     * Select the best prompt strategy based on historical performance.
     *
     * @param PromptIR         $candidate   proposed prompt
     * @param RenderContext    $context     visual context
     * @return PromptSelection  possibly a refined variant
     */
    public function optimize(
        PromptIR       $candidate,
        RenderContext  $context,
        Domain         $domain,
        Emotion        $emotion,
    ): PromptSelection { ... }

    /**
     * Record the outcome of a prompt + render for future learning.
     */
    public function record(PromptRecord $record): void { ... }

    /**
     * Return top-performing prompt patterns for a domain + emotion.
     */
    public function topPatterns(Domain $domain, Emotion $emotion, int $topK = 5): PromptPattern[] { ... }
}

final class PromptRecord
{
    public readonly string     $promptHash;       // SHA256 of canonical prompt
    public readonly string     $backendId;
    public readonly Domain     $domain;
    public readonly Emotion    $emotion;
    public readonly float      $qualityScore;     // from QualityEngine
    public readonly float      $identityScore;    // character consistency
    public readonly float      $promptAdherence;  // how well AI followed the prompt
    public readonly float      $costUsd;
    public readonly int        $latencySec;
    public readonly string     $promptSnapshot;   // serialized PromptIR key phrases
}

final class PromptPattern
{
    public readonly string     $patternId;
    public readonly string     $description;      // "slow push + warm key + intimate framing"
    public readonly float      $avgScore;
    public readonly int        $sampleSize;
    public readonly array      $signalPhrases;    // prompt phrases that correlate with success
}
```

**Storage:** `production_prompt_records` table — one row per rendered shot.
After 1,000+ records, `PromptIntelligence.topPatterns()` becomes meaningful.

### Subsystem 3C: Asset Dependency Graph

> "Change one asset, know exactly what downstream outputs are invalidated."

```php
namespace App\Services\AI\FilmOS\Dependencies;

final class AssetDependencyGraph
{
    /**
     * Register that outputId depends on sourceId.
     * Example: shot_video_12 depends on character_wardrobe_01
     */
    public function addDependency(string $sourceId, string $dependentId, DependencyType $type): void { ... }

    /**
     * When source changes, return all downstream outputs to invalidate.
     * Example: change wardrobe → invalidate all shot_video, thumbnails, continuity_frames
     */
    public function invalidate(string $sourceId): InvalidationSet { ... }

    /**
     * Full dependency tree for inspection/debug.
     */
    public function treeFor(string $nodeId): DependencyTree { ... }
}

final class InvalidationSet
{
    /** @var string[] — IDs of all invalidated outputs */
    public readonly array  $invalidatedIds;
    /** @var string[] — shot IDs that must be re-rendered */
    public readonly array  $shotsToRerender;
    /** @var string[] — assets that must be re-generated */
    public readonly array  $assetsToRegenerate;
    /** @var string[] — memories that must be cleared */
    public readonly array  $memoriesToClear;

    public function isEmpty(): bool { ... }
    public function rerenderCount(): int { ... }
    public function estimatedCostUsd(ProviderCatalog $catalog): float { ... }
}

enum DependencyType {
    case VISUAL_IDENTITY;    // appearance depends on character definition
    case CONTINUITY;         // shot continuity depends on previous shot's state
    case STYLE;              // shot depends on StyleBible
    case WORLD_STATE;        // shot depends on world state at that point
    case MEMORY;             // visual memory entry depends on source shot
    case THUMBNAIL;          // poster/thumbnail depends on shot frame
    case BUDGET;             // cost allocation depends on provider assignment
}
```

**Example dependency chain:**

```
CharacterDefinition.wardrobe ("navy blazer")
        │ VISUAL_IDENTITY
        ▼
AppearanceMemory.descriptor ("woman in navy blazer")
        │ CONTINUITY
        ▼
CharacterRenderDescriptor (shots 1–18)
        │ VISUAL_IDENTITY
        ▼
RenderContext.characters (shots 1–18)
        │ VISUAL_IDENTITY
        ▼
Kling prompt (shots 1–18)
        │
        ▼
shot_video_01 ... shot_video_18   ← ALL INVALIDATED on wardrobe change
```

### Subsystem 3D: Plugin Architecture

> "Every renderer, TTS, editor, and AI model is a plugin."

```php
namespace App\Services\AI\FilmOS\Plugin;

/**
 * Official extension point for FilmOS.
 * All external capabilities (renderers, voice, editors, AI models)
 * are registered here. No plugin imports from FilmOS core.
 */
final class PluginRegistry
{
    public function register(Plugin $plugin): self { ... }
    public function renderer(string $pluginId): RendererPlugin { ... }
    public function voice(string $pluginId): TTSPlugin { ... }
    public function editor(string $pluginId): EditorPlugin { ... }
    public function all(): Plugin[] { ... }
    public function defaults(): self { ... }    // registers built-in plugins
}

interface Plugin
{
    public function id(): string;
    public function version(): string;
    public function capabilities(): array;   // ["video", "image", "voice", "edit", "export"]
    public function isAvailable(): bool;     // API key configured + service reachable
}

interface RendererPlugin extends Plugin
{
    /**
     * Render a prompt to video/image.
     * Receives PromptIR + RenderContext — never FilmOS internal objects.
     */
    public function render(
        string        $promptText,    // serialized PromptIR from KlingBackend/VeoBackend
        RenderContext $context,
        array         $options,
    ): RenderedAsset { ... }

    public function estimateCost(float $durationSec, array $options): float { ... }
    public function estimateLatency(float $durationSec, array $options): int { ... }
}

interface TTSPlugin extends Plugin
{
    public function synthesize(string $text, VoiceStyle $style): AudioAsset { ... }
}

interface EditorPlugin extends Plugin
{
    public function canExport(ExportFormat $format): bool { ... }
    public function export(EditDecisionList $edl, ExportFormat $format): string { ... }
}
```

**Built-in plugins:**

```
app/Services/AI/FilmOS/Plugin/Plugins/
├── KlingPlugin.php          implements RendererPlugin
├── VeoPlugin.php            implements RendererPlugin
├── RunwayPlugin.php         implements RendererPlugin
├── SoraPlugin.php           implements RendererPlugin  (future)
├── FluxPlugin.php           implements RendererPlugin  (image)
├── ElevenLabsPlugin.php     implements TTSPlugin
├── WhisperPlugin.php        implements TTSPlugin        (transcription)
├── FinalCutPlugin.php       implements EditorPlugin
├── DaVinciPlugin.php        implements EditorPlugin
└── PremierePlugin.php       implements EditorPlugin
```

**Plugin vs. Backend (AFOS):**

| | AFOS Backend | FilmOS Plugin |
|---|---|---|
| What it does | Serializes PromptIR → prompt string | Calls external API, returns asset |
| Knows about FilmOS | No | No (receives only RenderContext) |
| Registered in | `BackendRegistry` (AFOS) | `PluginRegistry` (FilmOS) |
| Used by | `BackendEmitter` | `ProviderSelector` + `BudgetEngine` |
| Extension point | Adding new backend | Adding new renderer/TTS/editor |

---

## Complete Runtime Stack (ADR-001 through ADR-006)

```
┌──────────────────────────────────────────────────────────────────┐
│                    PRODUCER AI  (Phase G)                        │
│   Article → Research → BudgetEngine → PublishPipeline            │
├──────────────────────────────────────────────────────────────────┤
│                   DIRECTOR OS  (Phase G / ADR-006)               │
│  SemanticGraph → DirectorPlan → EmotionCurve → BlockingPlan      │
├──────────────────────────────────────────────────────────────────┤
│               EDITING OS — INTELLIGENCE  (ADR-006)               │
│  EditingIntelligence → Recommendations → EDL → FCP/DaVinci       │
├──────────────────────────────────────────────────────────────────┤
│         QUALITY + BUDGET + PROVIDER  (ADR-006)                   │
│  QualityEngine → QualityDecision (ACCEPT/RETRY/SWITCH)           │
│  BudgetEngine → ShotBudget → ProviderSelector → backendId        │
├──────────────────────────────────────────────────────────────────┤
│    SEMANTIC INTELLIGENCE  (ADR-006)                              │
│  SemanticGraph: CharacterRole · Relationship · Conflict          │
│  Payoff · ForeshadowElement · Symbol · Theme                     │
│  WorldStateEngine: AssetStateEntry · WorldEvent · StateHistory   │
├──────────────────────────────────────────────────────────────────┤
│  PLATFORM INTELLIGENCE  (ADR-006)                                │
│  FilmKnowledgeBase (cross-production StyleMemory + MotifLibrary) │
│  PromptIntelligence (learn from 1000s of renders)                │
│  AssetDependencyGraph (invalidation on change)                   │
│  PluginRegistry (Kling · Veo · Runway · ElevenLabs · DaVinci)   │
├──────────────────────────────────────────────────────────────────┤
│         VISUAL MEMORY (ADR-003 E)   ·   EDITING OS (ADR-003 F)  │
│  Appearance · Spatial · Lighting · Composition · Asset           │
├──────────────────────────────────────────────────────────────────┤
│  CHAR INTELLIGENCE (ADR-003 D) · VISUAL LANGUAGE (ADR-003 C)    │
│  CharacterBrain · Acting · MotionLibrary                         │
│  7 Bibles: Lens · Lighting · Composition · Movement · Color...   │
├──────────────────────────────────────────────────────────────────┤
│                   F I L M O S   C O R E  (ADR-002)              │
│  ProductionBible (Modules) · WorldModel · Character · Asset      │
│  ConstraintEngine (8 constraints) · SceneGraph v2                │
│  PlanningContext (5 sub-contexts) · ShotPlanner                  │
├──────────────────────────────────────────────────────────────────┤
│          A F O S   C O M P I L E R   v1  [FROZEN]               │
│  ShotGoalIR → CameraIR → PromptIR → BackendEmitter               │
│              ↑                                                   │
│          RenderContext (Amendment A)                             │
├──────────────────────────────────────────────────────────────────┤
│  EVENT BUS (ADR-004) · PERSISTENCE (ADR-005) · PLUGINS (ADR-006) │
└──────────────────────────────────────────────────────────────────┘
```

---

## Directory Structure (ADR-006 additions)

```
app/Services/AI/FilmOS/
├── Semantic/                   ← ADR-006 Group 1
│   ├── SemanticGraph.php
│   ├── SemanticGraphBuilder.php
│   ├── CharacterRole.php
│   ├── NarrativeRelationship.php
│   ├── ConflictNode.php
│   ├── Payoff.php
│   ├── ForeshadowElement.php
│   ├── SymbolRegistry.php
│   ├── ThemeRegistry.php
│   └── Enums/
│       ├── NarrativeRole.php
│       ├── RelationshipType.php
│       ├── ConflictType.php
│       ├── ConflictStatus.php
│       └── PayoffType.php
│
├── WorldState/                 ← ADR-006 Group 1
│   ├── WorldStateEngine.php
│   ├── WorldState.php
│   ├── WorldStateReport.php
│   ├── WorldEvent.php
│   ├── StateTransition.php
│   ├── StateHistory.php
│   ├── AssetStateEntry.php
│   └── Enums/
│       └── AssetPhysicalState.php
│
├── Director/                   ← ADR-006 Group 2
│   ├── DirectorOS.php
│   ├── DirectorPlan.php
│   ├── SceneDirectorPlan.php
│   ├── ShotDirectorDecision.php
│   ├── BlockingPlan.php
│   ├── ShotOrderPlan.php
│   ├── EmotionCurve.php
│   ├── PacingPlan.php
│   └── Enums/
│       ├── CameraApproach.php
│       └── DirectorPriority.php
│
├── Quality/                    ← ADR-006 Group 2
│   ├── QualityEngine.php
│   ├── QualityReport.php
│   ├── QualityDecision.php
│   ├── QualityFailure.php
│   └── Enums/
│       └── QualityAction.php
│
├── Budget/                     ← ADR-006 Group 2
│   ├── BudgetEngine.php
│   ├── ProviderSelector.php
│   ├── ProductionBudget.php
│   ├── BudgetAllocation.php
│   ├── ShotBudget.php
│   ├── ProviderCatalog.php
│   └── ProviderSpec.php
│
├── Knowledge/                  ← ADR-006 Group 3
│   ├── FilmKnowledgeBase.php
│   ├── StyleMemory.php
│   ├── DirectorMemory.php
│   ├── MotifLibrary.php
│   ├── MotifEntry.php
│   ├── CallbackLibrary.php
│   ├── CallbackPattern.php
│   └── DomainLibrary.php
│
├── PromptIntelligence/         ← ADR-006 Group 3
│   ├── PromptIntelligence.php
│   ├── PromptRecord.php
│   ├── PromptPattern.php
│   └── PromptSelection.php
│
├── Dependencies/               ← ADR-006 Group 3
│   ├── AssetDependencyGraph.php
│   ├── DependencyNode.php
│   ├── DependencyTree.php
│   ├── InvalidationSet.php
│   └── Enums/
│       └── DependencyType.php
│
└── Plugin/                     ← ADR-006 Group 3
    ├── PluginRegistry.php
    ├── Plugin.php               (interface)
    ├── RendererPlugin.php       (interface)
    ├── TTSPlugin.php            (interface)
    ├── EditorPlugin.php         (interface)
    ├── RenderedAsset.php
    └── Plugins/
        ├── KlingPlugin.php
        ├── VeoPlugin.php
        ├── RunwayPlugin.php
        ├── SoraPlugin.php
        ├── FluxPlugin.php
        ├── ElevenLabsPlugin.php
        ├── WhisperPlugin.php
        ├── FinalCutPlugin.php
        ├── DaVinciPlugin.php
        └── PremierePlugin.php

app/Services/AI/EditingOS/
└── Intelligence/               ← ADR-006 (extends ADR-003 Amendment F)
    ├── EditingIntelligence.php
    ├── EditingRecommendations.php
    ├── EditingRecommendation.php
    └── Enums/
        └── RecommendationType.php
```

---

## Additional Persistence (ADR-005 extension)

```sql
-- SemanticGraph per production
CREATE TABLE production_semantic_graphs (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL UNIQUE,
    payload        JSON NOT NULL,
    created_at     TIMESTAMP NOT NULL,
    updated_at     TIMESTAMP NOT NULL
);

-- WorldState history
CREATE TABLE production_world_states (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    shot_id        VARCHAR(36) NOT NULL,
    payload        JSON NOT NULL,  -- WorldState serialized
    created_at     TIMESTAMP NOT NULL,
    KEY idx_production_shot (production_id, shot_id)
);

-- PromptIntelligence records
CREATE TABLE production_prompt_records (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prompt_hash      CHAR(64) NOT NULL,      -- SHA256
    backend_id       VARCHAR(50) NOT NULL,
    domain           VARCHAR(50) NOT NULL,
    emotion          VARCHAR(50) NOT NULL,
    quality_score    FLOAT NOT NULL,
    identity_score   FLOAT NOT NULL,
    prompt_adherence FLOAT NOT NULL,
    cost_usd         DECIMAL(8,4) NOT NULL,
    latency_sec      SMALLINT UNSIGNED NOT NULL,
    prompt_snapshot  TEXT NOT NULL,
    created_at       TIMESTAMP NOT NULL,
    KEY idx_domain_emotion (domain, emotion),
    KEY idx_quality (quality_score)
);

-- Asset dependency graph
CREATE TABLE production_asset_dependencies (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id  VARCHAR(36) NOT NULL,
    source_id      VARCHAR(200) NOT NULL,
    dependent_id   VARCHAR(200) NOT NULL,
    dependency_type VARCHAR(50) NOT NULL,
    KEY idx_source (production_id, source_id),
    KEY idx_dependent (production_id, dependent_id)
);

-- QualityEngine reports
CREATE TABLE production_quality_reports (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id    VARCHAR(36) NOT NULL,
    shot_id          VARCHAR(36) NOT NULL,
    backend_id       VARCHAR(50) NOT NULL,
    overall_score    FLOAT NOT NULL,
    dimension_scores JSON NOT NULL,
    decision_action  VARCHAR(50) NOT NULL,
    attempt          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at       TIMESTAMP NOT NULL,
    KEY idx_production_shot (production_id, shot_id)
);
```

---

## Phase Roadmap Update

```
Phase B    FilmOS Core (ADR-002 + ADR-005)
Phase C    Visual Language Engine
Phase D    Character Intelligence
Phase E    Visual Memory
Phase F    EditingOS (exporter + intelligence layer)

Phase G1  ⭐       SemanticGraph + SemanticGraphBuilder
Phase G2  ⭐       WorldStateEngine + WorldStateConstraint
Phase G3  ⭐⭐⭐   DirectorOS + DirectorPlan + EmotionCurve
Phase G4  ⭐⭐⭐   QualityEngine + retry loop
Phase G5  ⭐⭐    BudgetEngine + ProviderSelector + ProviderCatalog
Phase G6  ⭐⭐⭐   PluginRegistry + built-in plugins (Kling, Veo, Runway)
Phase G7  ⭐⭐     AssetDependencyGraph + InvalidationEngine
Phase G8  ⭐⭐⭐   FilmKnowledgeBase + DomainLibrary
Phase G9  ⭐⭐     PromptIntelligence (needs 1000+ records first)
Phase G10 ⭐       EditingIntelligence (recommendations layer)
```

---

## Final Completion Estimate

| Layer | After B–F | After G1–G3 | After G4–G6 | After G7–G10 |
|-------|-----------|-------------|-------------|--------------|
| Compiler | 98% | 98% | 98% | 98% |
| FilmOS Core | 95% | 97% | 98% | 99% |
| Semantic Intelligence | 0% | 90% | 92% | 95% |
| Director Intelligence | 20% | 85% | 90% | 95% |
| Quality + Budget | 0% | 0% | 90% | 95% |
| Plugin Architecture | 30% | 35% | 90% | 95% |
| Knowledge + Learning | 0% | 0% | 0% | 85% |
| **Video quality vs. sample** | **94%** | **96%** | **97%** | **99%** |
| **Long-term scalability** | 85% | 90% | 95% | **99%** |

---

## Consequences

### Positive
- `SemanticGraph` makes the system understand **why** — every engine benefits without
  duplicating inference logic
- `WorldStateEngine` eliminates the most common AI continuity errors (door state, room state)
- `DirectorOS` elevates shot decisions from "grammar" to "vision" — the system has intent
- `QualityEngine` creates a **self-improving loop**: bad render → retry with better params
- `PromptIntelligence` compounds: the 1000th production benefits from all previous learnings
- `AssetDependencyGraph` makes changes safe: change one thing, know exactly what breaks
- `PluginRegistry` ensures the core never changes when new AI models are released

### Negative
- `SemanticGraph` quality depends on story richness — simple articles produce shallow graphs
- `DirectorOS` rules require careful curation per domain; wrong rules harm quality
- `QualityEngine` requires vision model integration (CLIP or equivalent) — external dependency
- `PromptIntelligence` is meaningless without 1000+ production records — Phase G9 is last for a reason
- Total architecture is now large — junior contributors need strong documentation to navigate

### The key design truth

After ADR-006, every AI model in the system is a **plugin**.
The intelligence lives in FilmOS — not in Kling, not in Veo, not in GPT.
When Sora 3.0 launches, it plugs in. FilmOS does not change.
When a better TTS releases, it plugs in. FilmOS does not change.
The models are interchangeable tools.
FilmOS is the director.

---

## References

- ADR-001: Freeze AFOS Compiler Core
- ADR-002: FilmOS Unified Model
- ADR-003: FilmOS Extended Engines
- ADR-004: Production Event Bus
- ADR-005: Persistence Model
- `app/Services/AI/AFOS/Benchmark/QAEngine.php` — existing QA evaluator (Phase A analogue)
- `app/Services/AI/SceneGraph/ContinuityEngine.php` — superseded by WorldStateEngine
- `app/Services/AI/AFOS/Cost/CostModel.php` — existing cost model (extended by BudgetEngine)
