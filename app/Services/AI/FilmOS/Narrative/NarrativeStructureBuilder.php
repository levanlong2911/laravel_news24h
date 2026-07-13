<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative;

use App\Services\AI\FilmOS\Meaning\CinematicFunction;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Meaning\MeaningNode;

/**
 * Translates a MeaningGraph into a NarrativeGraph.
 *
 * Reads the CinematicFunction assigned to each MeaningNode and maps it
 * to a cinematic beat. When multiple nodes map to the same beat, the
 * highest-weight node becomes the canonical representative for that beat.
 *
 * Mapping:
 *   OBSERVE / ESTABLISH → hook
 *   ESCALATE             → escalation
 *   REVEAL / ECHO        → reveal
 *   RESOLVE              → payoff
 */
final class NarrativeStructureBuilder
{
    public function build(MeaningGraph $meaning): NarrativeGraph
    {
        $narrative = new NarrativeGraph();

        /** @var array<string, MeaningNode> $beatBest highest-weight node per beat */
        $beatBest = [];

        foreach ($meaning->nodes() as $node) {
            /** @var MeaningNode $node */
            if ($node->cinematicFunction === null) {
                continue;
            }

            $beat = $this->toBeat($node->cinematicFunction);

            if (!isset($beatBest[$beat]) || $node->weight > $beatBest[$beat]->weight) {
                $beatBest[$beat] = $node;
            }
        }

        $order = 1;
        foreach (['hook', 'escalation', 'reveal', 'payoff'] as $beat) {
            if (!isset($beatBest[$beat])) {
                continue;
            }
            $mn = $beatBest[$beat];
            $narrative->addNode(new NarrativeNode("beat_{$order}", $beat, $mn->concept, $mn->weight));
            $order++;
        }

        return $narrative;
    }

    private function toBeat(CinematicFunction $fn): string
    {
        return match ($fn) {
            CinematicFunction::OBSERVE,
            CinematicFunction::ESTABLISH => 'hook',
            CinematicFunction::ESCALATE  => 'escalation',
            CinematicFunction::REVEAL,
            CinematicFunction::ECHO      => 'reveal',
            CinematicFunction::RESOLVE   => 'payoff',
        };
    }
}
