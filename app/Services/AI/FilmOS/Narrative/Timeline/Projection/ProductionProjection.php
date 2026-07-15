<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\Production\ConflictPlan;
use App\Services\AI\FilmOS\Narrative\Production\DirectorIntent;
use App\Services\AI\FilmOS\Narrative\Production\HeroMoment;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Production\ProductionView;

/**
 * Snapshot of production (staging) knowledge at a given timeline point.
 *
 * Materializes the latest ProductionPlan (last-write-wins — a duplicate
 * ProductionPlannedEvent overwrites; flagging duplicates is a future QA rule).
 * A production without a plan is valid: every accessor returns null/empty.
 *
 * Point lookups are O(1): ordinal indexes are built ONCE at construction
 * (projection time), because energyAt/durationAt sit on the hot path of
 * Compiler → Adapter → QA → Renderer and productions will grow to 20–80 shots.
 *
 * Prompting and planners MUST depend on ProductionView, not this class.
 */
final class ProductionProjection implements ProductionView
{
    /** @var array<int, int> ordinal => energy value */
    private readonly array $energyIndex;

    /** @var array<int, float> ordinal => duration seconds */
    private readonly array $timingIndex;

    public function __construct(
        private readonly ?ProductionPlan $plan = null,
    ) {
        $energy = [];
        foreach ($plan?->energyPoints ?? [] as $point) {
            $energy[$point->ordinal] = $point->value;   // last-write-wins per ordinal
        }
        $this->energyIndex = $energy;

        $timing = [];
        foreach ($plan?->timings ?? [] as $shotTiming) {
            $timing[$shotTiming->ordinal] = $shotTiming->durationSeconds;
        }
        $this->timingIndex = $timing;
    }

    public function intent(): ?DirectorIntent
    {
        return $this->plan?->intent;
    }

    public function conflictPlan(): ?ConflictPlan
    {
        return $this->plan?->conflictPlan;
    }

    public function motifs(): array
    {
        return $this->plan?->motifs ?? [];
    }

    public function constraints(): array
    {
        return $this->plan?->constraints ?? [];
    }

    public function heroMoment(): ?HeroMoment
    {
        return $this->plan?->heroMoment;
    }

    public function energyAt(int $ordinal): ?int
    {
        return $this->energyIndex[$ordinal] ?? null;
    }

    public function durationAt(int $ordinal): ?float
    {
        return $this->timingIndex[$ordinal] ?? null;
    }

    public function energyCurve(): array
    {
        return $this->plan?->energyPoints ?? [];
    }

    public function timings(): array
    {
        return $this->plan?->timings ?? [];
    }
}
