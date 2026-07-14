<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Story;

/**
 * Cinematic beat of a shot — first-class narrative knowledge.
 *
 * Source of truth: NarrativeStructureBuilder assigns beats from CinematicFunction.
 * The beat then FLOWS as a typed value (NarrativeNode → GoalNode →
 * ShotPlannedEvent → StoryShot) — it is never derived by parsing identifiers.
 *
 * Case declaration order IS the cinematic order (used by NarrativeGraph::orderedBeats).
 */
enum StoryBeat: string
{
    case HOOK       = 'hook';
    case ESCALATION = 'escalation';
    case REVEAL     = 'reveal';
    case PAYOFF     = 'payoff';
}
