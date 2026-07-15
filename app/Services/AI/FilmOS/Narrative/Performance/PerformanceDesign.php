<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

/**
 * The complete acting design for a production — payload of
 * PerformanceDirectedEvent (authored by humans in v1; a Performance Planner
 * may generate it once benchmark data says which acting decisions matter).
 *
 * Immutable.
 */
final class PerformanceDesign
{
    /** @param CharacterPerformance[] $performances */
    public function __construct(
        public readonly array $performances = [],
    ) {}
}
