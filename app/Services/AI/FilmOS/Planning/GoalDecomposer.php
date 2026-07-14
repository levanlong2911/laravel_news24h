<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Narrative\NarrativeGraph;
use App\Services\AI\FilmOS\Narrative\NarrativeNode;

/**
 * Translates a NarrativeGraph into a GoalGraph.
 *
 * Each NarrativeNode (beat + concept + weight) becomes one leaf GoalNode.
 * GoalDecomposer has no knowledge of domains or cinematic structure —
 * that responsibility belongs to NarrativeStructureBuilder.
 *
 * Adding new narrative structures (cold open, flashback, twist) only
 * requires changes to NarrativeStructureBuilder, not here.
 */
final class GoalDecomposer
{
    public function decompose(NarrativeGraph $narrative): GoalGraph
    {
        if ($narrative->isEmpty()) {
            return $this->genericFallback();
        }

        $graph = new GoalGraph('root');
        $graph->addNode(new GoalNode('root', 'Cinematic story', GoalNodeType::ROOT, 0.88));

        foreach ($narrative->orderedBeats() as $node) {
            /** @var NarrativeNode $node */
            $goalId = "shot_{$node->beat->value}";
            $graph->addNode(new GoalNode(
                $goalId,
                ucfirst(str_replace('_', ' ', $node->concept)),
                GoalNodeType::LEAF,
                $node->weight,
                maxShots: 1,
                beat:     $node->beat,   // typed pass-through from NarrativeStructureBuilder
            ));
            $graph->addEdge(new GoalEdge('root', $goalId, GoalRelation::REQUIRES));
        }

        return $graph;
    }

    private function genericFallback(): GoalGraph
    {
        $graph = new GoalGraph('root');
        $graph->addNode(new GoalNode('root',   'Communicate story', GoalNodeType::ROOT, 0.80));
        $graph->addNode(new GoalNode('shot_1', 'Main shot',         GoalNodeType::LEAF, 0.80, maxShots: 1));
        $graph->addEdge(new GoalEdge('root', 'shot_1', GoalRelation::REQUIRES));
        return $graph;
    }
}
