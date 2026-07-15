<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

/**
 * Read-only view of production (staging) knowledge at a projection point.
 *
 * Ownership: Narrative answers "what happens"; Production answers "how it is
 * staged — what must the audience feel, where is the peak, what rhythm";
 * Prompting answers "how to say that to a vendor". This view carries ONLY
 * semantic staging knowledge — no prompt syntax, no vendor wording, ever
 * (reusable by Kling adapters today, Unreal/Blender timelines tomorrow).
 *
 * Both point APIs (energyAt/durationAt) and collection APIs
 * (energyCurve/timings) are provided: adapters look up per shot,
 * planners look at the whole curve.
 */
interface ProductionView
{
    public function intent(): ?DirectorIntent;

    public function conflictPlan(): ?ConflictPlan;

    /** @return VisualMotif[] */
    public function motifs(): array;

    /** @return VisualConstraint[] */
    public function constraints(): array;

    public function heroMoment(): ?HeroMoment;

    /** Energy value at exactly this ordinal, or null if no point set (no interpolation in v1). */
    public function energyAt(int $ordinal): ?int;

    /** Planned duration for this ordinal, or null if no timing set. */
    public function durationAt(int $ordinal): ?float;

    /** @return EnergyPoint[] the whole curve, for planners/adapters that need global shape */
    public function energyCurve(): array;

    /** @return ShotTiming[] all pacing decisions */
    public function timings(): array;
}
