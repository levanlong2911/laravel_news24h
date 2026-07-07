<?php

namespace App\Services\AI\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Types\Emotion;

/**
 * PhraseCatalogInterface — vocabulary contract for Kling-specific prose.
 *
 * Phase A: implemented by StaticPhraseCatalog (hardcoded villa + common domains).
 * Phase B: replaced by WorldModulePhraseCatalog, which reads EntityDefinition.cinematicPhrase()
 *          from ProductionBible — no AFOS changes required.
 *
 * KlingPromptPlanningPass depends on this interface only.
 * The compiler never knows which implementation is active.
 */
interface PhraseCatalogInterface
{
    /**
     * Returns a Kling-ready cinematic phrase for a given entity reference.
     *
     * Phase A: looks up static vocabulary.
     * Phase B: returns EntityDefinition.cinematicPhrase() from WorldModule.
     *
     * Must never return an empty string. Fallback to humanized entity ID is acceptable.
     */
    public function cinematicPhrase(string $entityRef): string;

    /**
     * Returns an atmosphere clause variant for the given emotion + shot context.
     *
     * Selection must be deterministic: same (emotion, shotId) → same variant.
     * This ensures reproducible output without randomness.
     *
     * Phase A: selects from static variants array, indexed by crc32(shotId).
     * Phase B: may enrich with WorldModel lighting context.
     */
    public function atmosphereVariant(Emotion $emotion, string $shotId): string;
}
