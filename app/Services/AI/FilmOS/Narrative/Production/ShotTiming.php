<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * Pacing decision for one shot: (ordinal, seconds).
 * Lives in Production because pacing is a STAGING decision —
 * a shot does not know its own duration.
 *
 * Immutable.
 */
final class ShotTiming
{
    public function __construct(
        public readonly int   $ordinal,
        public readonly float $durationSeconds,
    ) {}
}
