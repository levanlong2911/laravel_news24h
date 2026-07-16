<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Plan;

/**
 * WHAT the prompt is allowed to contain — every decision made, nothing worded.
 *
 *   StructuredPrompt → RenderPlanner → RenderPlan → (any) Renderer → prompt
 *
 * The plan knows nothing about Kling, Veo, Runway, word budgets or English. It
 * has already resolved ownership (one source owns each piece of information, so
 * nothing is said twice), staging (who is in frame in which beat), ordering and
 * importance. A renderer then only decides HOW to say it, and — because only it
 * knows what its own wording costs — how much of it fits.
 *
 * Grouped rather than flat so a renderer never has to re-derive structure:
 * global setup, then the beats in order, then the payoff frame, then constraints.
 *
 * Immutable.
 */
final class RenderPlan
{
    /**
     * @param PlanItem[] $global      look, subject identity, anatomy, environment, motifs
     * @param BeatPlan[] $beats       in cinematic order
     * @param PlanItem[] $ending      the hero moment
     * @param PlanItem[] $constraints ALWAYS (positive) and NEVER (negative)
     */
    public function __construct(
        public readonly array $global      = [],
        public readonly array $beats       = [],
        public readonly array $ending      = [],
        public readonly array $constraints = [],
    ) {}
}
