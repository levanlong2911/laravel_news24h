<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

/**
 * The acting direction for ONE character in ONE shot.
 *
 * SEQUENCE INVARIANT (the anti-keyframe rule, frozen 2026-07-13):
 * $cues array order IS temporal order inside the shot — "this, then that".
 * There are NO timestamps, seconds, or percentages inside a shot. Micro-timing
 * belongs to the video model (or a future engine adapter). This is what keeps
 * Performance a knowledge domain instead of an animation rig.
 *
 * NO PERSISTENCE — deliberately unlike D2 emotion: acting is per-shot
 * behavior. Absence at an ordinal means "no direction", never "repeat the
 * previous shot".
 *
 * Evolution note: if benchmarks show many characters sharing one intent in
 * the same shot, a ShotPerformance grouping (sharedIntent + characters[])
 * may be introduced additively — flagged, not needed at current scale.
 *
 * Immutable.
 */
final class CharacterPerformance
{
    /** @param PerformanceCue[] $cues ordered — array order = temporal order */
    public function __construct(
        public readonly string             $characterId,
        public readonly int                $ordinal,
        public readonly ?PerformanceIntent $intent = null,
        public readonly array              $cues = [],
    ) {}
}
