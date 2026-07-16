<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

/**
 * The decided content of one beat. Keeps the beat's identity (ordinal + StoryBeat)
 * so a renderer can label the block in its own language — "HOOK" is Kling's
 * choice of word, not the plan's.
 *
 * Immutable.
 */
final class BeatPlan
{
    /** @param PlanItem[] $items already ordered */
    public function __construct(
        public readonly int        $ordinal,
        public readonly ?StoryBeat $beat,
        public readonly array      $items = [],
    ) {}
}
