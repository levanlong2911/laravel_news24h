<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * THE frame the whole piece is built to reach.
 *
 * Anchored by ORDINAL, not beat: beat vocabularies may evolve
 * (HOOK/SETUP/BUILD/…), ordinals are frozen shot identity (D1).
 * A hero moment is a specific frame, not a category of beat.
 *
 * Immutable.
 */
final class HeroMoment
{
    public function __construct(
        public readonly int    $ordinal,
        public readonly string $description,
    ) {}
}
