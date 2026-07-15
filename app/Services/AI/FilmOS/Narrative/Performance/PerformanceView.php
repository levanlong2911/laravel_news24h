<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

/**
 * Read-only view of acting knowledge at a projection point.
 *
 * Exactly THREE methods — knowledge APIs stay minimal (frozen 2026-07-13):
 * anything else is derivable from allPerformances() by the consumer.
 *
 * NO persistence semantics: performance is per-shot behavior. A null from
 * performanceOf() means "no acting direction for this shot" — adapters fall
 * back to rendering from emotion alone.
 */
interface PerformanceView
{
    public function performanceOf(string $characterId, int $ordinal): ?CharacterPerformance;

    /** @return array<string, CharacterPerformance> characterId => performance at this ordinal */
    public function performancesAt(int $ordinal): array;

    /** @return CharacterPerformance[] every acting direction in the production */
    public function allPerformances(): array;
}
