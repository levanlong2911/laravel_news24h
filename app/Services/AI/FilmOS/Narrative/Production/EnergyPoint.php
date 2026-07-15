<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * One point on the cinematic energy curve: (ordinal, 0–100, why).
 *
 * $reason is benchmark gold: "100 because ball leaves hand" teaches a future
 * Director Planner WHY energy peaked — a bare number never could. Nullable:
 * authors state reasons when they matter.
 *
 * Discrete points now; a spline interpolation later never changes this contract.
 *
 * Immutable.
 */
final class EnergyPoint
{
    public function __construct(
        public readonly int     $ordinal,
        public readonly int     $value,           // 0–100
        public readonly ?string $reason = null,   // semantic cause, e.g. "ball leaves hand"
    ) {}
}
