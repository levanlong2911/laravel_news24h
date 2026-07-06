# ADR-007: Capability Resolution & Backend Abstraction

**Status:** Draft  
**Date:** 2026-07-06  
**Deciders:** Project Lead  
**Depends on:** ADR-001, ADR-002, ADR-004, ADR-006  
**Planned phase:** Phase G (after Phase B–F complete)

---

## Context

ADR-006 introduced `PluginRegistry` and `RendererPlugin` to abstract renderer implementations.
However, the current dispatch path is still **model-first**:

```
ShotCompiled
  └── ProviderSelector (ADR-006)
        ├── if domain == REALISTIC → Kling
        ├── if domain == ANIMATED  → RunwayML
        └── if priority == FILLER  → Kling Lite
```

Problems with model-first selection:
1. FilmOS "knows" provider names — adding Flux 3 or Imagen 4 requires modifying FilmOS
2. Selection logic conflates *capability matching* with *cost optimization*
3. Different shots needing the same output characteristics may route differently
4. RendererPlugin implementations differ in what they support
   (Kling supports image-to-video; some backends don't; this is currently hardcoded)

The goal is **capability-first architecture**:
FilmOS describes *what it needs*. The resolver picks *who provides it*.
FilmOS never names a model.

---

## Decision

Introduce a `CapabilityResolution` layer between FilmOS shot dispatch and backend plugins.

```
ShotCompiled
  └── CapabilitySpec (what is needed)
        └── CapabilityResolver
              └── CapabilityCatalog (what each provider supports)
                    └── BackendSelector (best match by capability + cost + priority)
                          └── RendererPlugin (actual execution)
```

---

## Core Types

### CapabilitySpec

Describes the output requirements for a single shot — entirely backend-agnostic:

```php
namespace App\Services\AI\FilmOS\Capability;

final class CapabilitySpec
{
    public function __construct(
        public readonly Resolution        $resolution,        // 768x1344, 1080x1920, etc.
        public readonly AspectRatio       $aspectRatio,
        public readonly RenderStyle       $style,             // PHOTOREAL, ANIMATED, CINEMATIC
        public readonly MotionType        $motionType,        // STATIC, WALK, CAMERA_MOVE, etc.
        public readonly float             $durationSeconds,
        public readonly bool              $requiresImageFirst,       // image-to-video pipeline
        public readonly bool              $requiresCharacterConsistency,
        public readonly bool              $requiresLipSync,
        public readonly bool              $requiresDepthOfField,
        public readonly QualityTier       $qualityTier,       // ULTRA, HIGH, STANDARD, DRAFT
        public readonly ?string           $referenceImageId,  // for style-lock
    ) {}
}
```

### CapabilityCatalog

Registry of what each provider can do:

```php
final class ProviderProfile
{
    public function __construct(
        public readonly string       $providerId,           // 'kling_v2', 'veo_ultra', 'runway_gen4'
        public readonly string       $displayName,
        public readonly array        $supportedResolutions, // Resolution[]
        public readonly array        $supportedStyles,      // RenderStyle[]
        public readonly array        $supportedMotionTypes, // MotionType[]
        public readonly float        $minDurationSeconds,
        public readonly float        $maxDurationSeconds,
        public readonly bool         $supportsImageFirst,
        public readonly bool         $supportsCharacterConsistency,
        public readonly bool         $supportsLipSync,
        public readonly bool         $supportsDepthOfField,
        public readonly array        $qualityTiers,          // QualityTier[]
        public readonly float        $costPerSecondUsd,
        public readonly int          $rateLimit,             // requests/min
        public readonly bool         $isAvailable,           // live API status
    ) {}
}

final class CapabilityCatalog
{
    /** @param ProviderProfile[] $profiles */
    public function __construct(private readonly array $profiles) {}

    /** Returns all profiles that fully satisfy the given spec. */
    public function matching(CapabilitySpec $spec): array { ... }

    /** Returns all profiles registered, regardless of spec. */
    public function all(): array { ... }
}
```

### CapabilityResolver

Core matching + selection logic:

```php
interface CapabilityResolver
{
    /**
     * Given a spec and a budget envelope, return the optimal provider.
     * Returns null if no provider can satisfy the spec.
     */
    public function resolve(
        CapabilitySpec  $spec,
        BudgetEnvelope  $envelope,
        ShotPriority    $priority,
    ): ?ProviderProfile;

    /**
     * Returns ranked candidates (for Decision Engine use — ADR-010).
     *
     * @return ProviderProfile[]
     */
    public function candidates(
        CapabilitySpec $spec,
        BudgetEnvelope $envelope,
    ): array;
}
```

Default implementation — `CostAwareCapabilityResolver`:

```php
final class CostAwareCapabilityResolver implements CapabilityResolver
{
    public function resolve(
        CapabilitySpec $spec,
        BudgetEnvelope $envelope,
        ShotPriority   $priority,
    ): ?ProviderProfile {
        $candidates = $this->catalog->matching($spec);

        // Filter: cost within envelope
        $affordable = array_filter(
            $candidates,
            fn($p) => $p->costPerSecondUsd * $spec->durationSeconds <= $envelope->maxCostUsd,
        );

        if (empty($affordable)) {
            return null;
        }

        // CRITICAL shots → highest quality tier among affordable
        // FILLER shots → cheapest among affordable
        return $priority === ShotPriority::CRITICAL
            ? $this->highestQuality($affordable)
            : $this->cheapest($affordable);
    }
}
```

---

## Integration with Existing Systems

### CapabilitySpec is built by PlanningContextBuilder

```php
// Phase G — CapabilitySpecBuilder (new component)
final class CapabilitySpecBuilder
{
    public function build(
        ShotContext    $shot,
        VisualContext  $visual,
        CharacterContext $character,
        DirectorPlan   $directorPlan,
    ): CapabilitySpec {
        return new CapabilitySpec(
            resolution:                    $visual->targetResolution,
            style:                         $visual->renderStyle,
            requiresCharacterConsistency:  count($character->characters) > 0,
            requiresImageFirst:            $visual->useImageFirst,
            qualityTier:                   $directorPlan->shotPriority($shot->shotId)->toQualityTier(),
            // ...
        );
    }
}
```

### CapabilityResolver replaces ProviderSelector

ADR-006 `ProviderSelector` is **superseded** by `CapabilityResolver`.
`ProviderSelector` had hardcoded `if domain == X → provider Y` logic.
`CapabilityResolver` has no hardcoded provider names.

```php
// BEFORE (model-first, ADR-006 ProviderSelector):
$providerId = $this->providerSelector->selectFor($shotPriority, $domain);

// AFTER (capability-first, ADR-007):
$spec     = $this->specBuilder->build($shot, $visual, $character, $directorPlan);
$provider = $this->capabilityResolver->resolve($spec, $envelope, $shotPriority);
$plugin   = $this->pluginRegistry->get($provider->providerId);
```

### CapabilityCatalog is loaded from config

```php
// config/filmos_providers.php
return [
    'providers' => [
        [
            'providerId'                    => 'kling_v2',
            'supportedResolutions'          => ['768x1344', '1080x1920'],
            'supportsCharacterConsistency'  => true,
            'supportsImageFirst'            => true,
            'costPerSecondUsd'              => 0.08,
            // ...
        ],
        [
            'providerId'                    => 'veo_ultra',
            'supportedResolutions'          => ['1080x1920', '2160x3840'],
            'supportsCharacterConsistency'  => true,
            'supportsLipSync'               => true,
            'costPerSecondUsd'              => 0.35,
            // ...
        ],
    ],
];
```

Adding a new provider (Flux 3, Sora 2, Imagen 4): **add one entry to config + one RendererPlugin class. FilmOS core does not change.**

---

## Directory Structure

```
app/Services/AI/FilmOS/Capability/
├── CapabilitySpec.php
├── CapabilityCatalog.php
├── ProviderProfile.php
├── CapabilityResolver.php              (interface)
├── CostAwareCapabilityResolver.php     (default impl)
├── CapabilitySpecBuilder.php
├── Enums/
│   ├── RenderStyle.php
│   ├── MotionType.php
│   ├── QualityTier.php
│   └── Resolution.php
└── BudgetEnvelope.php
```

---

## Consequences

### Positive
- Adding a new AI model = config entry + RendererPlugin. Zero FilmOS core changes.
- CapabilitySpec is a stable contract — it describes what a shot needs, not which model renders it
- Enables multi-candidate dispatch for Decision Engine (ADR-010): resolve 3 candidates, render in parallel, pick best
- Rate limiting, failover, and A/B testing become config changes
- BudgetEngine (ADR-006) plugs into `BudgetEnvelope` naturally

### Negative
- CapabilityCatalog must be kept up-to-date as provider APIs evolve (mitigated: config-driven)
- Edge case: no provider satisfies spec → resolution fallback strategy needed

### Not changing
- AFOS Compiler — unchanged
- RendererPlugin interface (ADR-006) — unchanged, just now selected by CapabilityResolver
- PlanningContext (ADR-002) — unchanged, CapabilitySpecBuilder reads from it

---

## Open Questions

1. **Capability mismatch fallback:** If no provider supports all required capabilities, should the resolver (a) relax lowest-priority capability first, (b) split into image + video segments, or (c) fail the shot? → TBD Phase G
2. **Dynamic availability:** Should `CapabilityCatalog` poll live API status, or trust config? → Start with config, add health-check in Phase G3
3. **ProviderProfile versioning:** Same provider may have v1/v2 with different capabilities. Model as separate entries or as version field? → TBD

---

## References

- ADR-001: AFOS Compiler (frozen — CapabilityResolver is above AFOS)
- ADR-004: Production Event Bus (CapabilityResolved event to emit)
- ADR-006: PluginRegistry + RendererPlugin (CapabilityResolver selects the plugin)
- ADR-006: ProviderSelector (superseded by this ADR)
- ADR-010: Decision Engine (uses `resolver.candidates()` for multi-candidate renders)
