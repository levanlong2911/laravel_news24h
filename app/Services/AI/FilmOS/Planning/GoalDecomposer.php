<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Meaning\MeaningGraph;

/**
 * Phase 1: one template per domain — travel_warning only.
 * Decomposes MeaningGraph root goal into GoalGraph with REQUIRES edges.
 */
final class GoalDecomposer
{
    public function decompose(MeaningGraph $meaning, string $domain): GoalGraph
    {
        return match ($domain) {
            'travel_warning' => $this->travelWarningTemplate($meaning),
            default          => $this->genericTemplate($meaning),
        };
    }

    private function travelWarningTemplate(MeaningGraph $meaning): GoalGraph
    {
        $graph = new GoalGraph('root');

        $graph->addNode(new GoalNode('root',           'Warn travelers about hotel safety',   GoalNodeType::ROOT,         0.95));
        $graph->addNode(new GoalNode('context',        'Establish context',                   GoalNodeType::INTERMEDIATE, 0.70));
        $graph->addNode(new GoalNode('evidence',       'Present evidence',                    GoalNodeType::INTERMEDIATE, 0.90));
        $graph->addNode(new GoalNode('hotel_exterior', 'Hotel exterior establishing shot',     GoalNodeType::LEAF,         0.70, 1));
        $graph->addNode(new GoalNode('cockroach_closeup', 'Cockroach close-up in room',        GoalNodeType::LEAF,         0.95, 1));
        $graph->addNode(new GoalNode('health_notice',  'Health department notice',             GoalNodeType::LEAF,         0.85, 1));
        $graph->addNode(new GoalNode('travel_advisory','Travel advisory recommendation',       GoalNodeType::LEAF,         0.80, 1));

        // root REQUIRES context and evidence
        $graph->addEdge(new GoalEdge('root', 'context',        GoalRelation::REQUIRES));
        $graph->addEdge(new GoalEdge('root', 'evidence',       GoalRelation::REQUIRES));
        // context REQUIRES hotel_exterior leaf
        $graph->addEdge(new GoalEdge('context',  'hotel_exterior',   GoalRelation::REQUIRES));
        // evidence REQUIRES closeup and notice
        $graph->addEdge(new GoalEdge('evidence', 'cockroach_closeup', GoalRelation::REQUIRES));
        $graph->addEdge(new GoalEdge('evidence', 'health_notice',     GoalRelation::REQUIRES));
        // advisory comes after all evidence shots (sequence constraint)
        $graph->addEdge(new GoalEdge('root',          'travel_advisory', GoalRelation::SUPPORTS));
        $graph->addEdge(new GoalEdge('health_notice', 'travel_advisory', GoalRelation::REQUIRES));

        return $graph;
    }

    private function genericTemplate(MeaningGraph $meaning): GoalGraph
    {
        $graph = new GoalGraph('root');
        $graph->addNode(new GoalNode('root',   'Communicate story', GoalNodeType::ROOT, 0.80));
        $graph->addNode(new GoalNode('shot_1', 'Main shot',         GoalNodeType::LEAF, 0.80, 1));
        $graph->addEdge(new GoalEdge('root', 'shot_1', GoalRelation::REQUIRES));
        return $graph;
    }
}
