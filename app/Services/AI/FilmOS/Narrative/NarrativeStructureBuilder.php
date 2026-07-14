<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative;

use App\Services\AI\FilmOS\Meaning\CinematicFunction;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Meaning\MeaningNode;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

/**
 * Translates a MeaningGraph into a NarrativeGraph.
 *
 * SOURCE OF TRUTH for beat assignment: this is the only place where
 * CinematicFunction becomes a StoryBeat. The beat then flows as a typed
 * value through GoalNode → ShotPlannedEvent → StoryShot; downstream code
 * never re-derives it from identifiers.
 *
 * When multiple nodes map to the same beat, the highest-weight node
 * becomes the canonical representative for that beat.
 *
 * Mapping:
 *   OBSERVE / ESTABLISH → HOOK
 *   ESCALATE             → ESCALATION
 *   REVEAL / ECHO        → REVEAL
 *   RESOLVE              → PAYOFF
 */
final class NarrativeStructureBuilder
{
    public function build(MeaningGraph $meaning): NarrativeGraph
    {
        $narrative = new NarrativeGraph();

        /** @var array<string, MeaningNode> $beatBest highest-weight node per beat value */
        $beatBest = [];

        foreach ($meaning->nodes() as $node) {
            /** @var MeaningNode $node */
            if ($node->cinematicFunction === null) {
                continue;
            }

            $beat = $this->toBeat($node->cinematicFunction);

            if (!isset($beatBest[$beat->value]) || $node->weight > $beatBest[$beat->value]->weight) {
                $beatBest[$beat->value] = $node;
            }
        }

        $order = 1;
        foreach (StoryBeat::cases() as $beat) {
            if (!isset($beatBest[$beat->value])) {
                continue;
            }
            $mn = $beatBest[$beat->value];
            $narrative->addNode(new NarrativeNode("beat_{$order}", $beat, $mn->concept, $mn->weight));
            $order++;
        }

        return $narrative;
    }

    private function toBeat(CinematicFunction $fn): StoryBeat
    {
        return match ($fn) {
            CinematicFunction::OBSERVE,
            CinematicFunction::ESTABLISH => StoryBeat::HOOK,
            CinematicFunction::ESCALATE  => StoryBeat::ESCALATION,
            CinematicFunction::REVEAL,
            CinematicFunction::ECHO      => StoryBeat::REVEAL,
            CinematicFunction::RESOLVE   => StoryBeat::PAYOFF,
        };
    }
}
