<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\Performance\CharacterPerformance;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceView;

/**
 * Snapshot of acting knowledge at a given timeline point.
 *
 * Materializes the latest PerformanceDesign (last-write-wins — duplicate
 * PerformanceDirectedEvent overwrites; flagging is a future QA rule).
 * A production without acting direction is valid: accessors return null/empty.
 *
 * Lookups are O(1): the [ordinal][characterId] index is built ONCE at
 * construction (the Production Layer lesson — this sits on the
 * Compiler → Adapter hot path).
 *
 * Prompting and planners MUST depend on PerformanceView, not this class.
 */
final class PerformanceProjection implements PerformanceView
{
    /** @var array<int, array<string, CharacterPerformance>> ordinal => characterId => performance */
    private readonly array $index;

    public function __construct(
        private readonly ?PerformanceDesign $design = null,
    ) {
        $index = [];
        foreach ($design?->performances ?? [] as $performance) {
            // last-write-wins per (ordinal, characterId)
            $index[$performance->ordinal][$performance->characterId] = $performance;
        }
        $this->index = $index;
    }

    public function performanceOf(string $characterId, int $ordinal): ?CharacterPerformance
    {
        return $this->index[$ordinal][$characterId] ?? null;
    }

    public function performancesAt(int $ordinal): array
    {
        return $this->index[$ordinal] ?? [];
    }

    public function allPerformances(): array
    {
        return $this->design?->performances ?? [];
    }
}
