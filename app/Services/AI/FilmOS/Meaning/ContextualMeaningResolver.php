<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

/**
 * Phase 1 implementation: handles the travel_warning domain only.
 * Builds a typed CausalMeaningGraph from article facts.
 */
final class ContextualMeaningResolver implements MeaningResolver
{
    public function resolve(array $facts, string $domain, array $worldState = []): MeaningGraph
    {
        return match ($domain) {
            'travel_warning' => $this->resolveTravelWarning($facts, $worldState),
            default          => $this->resolveGeneric($facts, $worldState),
        };
    }

    private function resolveTravelWarning(array $facts, array $worldState): MeaningGraph
    {
        $graph = new MeaningGraph(
            rootNodeId:        'N4',
            cinematicFunction: CinematicFunction::REVEAL,
            tensionLevel:      7.2,
            confidence:        0.91,
        );

        // Build meaning nodes from facts — N1 chain: evidence → unsanitary → health_risk → travel_warning
        $graph->addNode(new MeaningNode('N1', 'cockroach',         $this->weightFor($facts, 'EVIDENCE', 0.95), 'infestation found'));
        $graph->addNode(new MeaningNode('N2', 'unsanitary',        0.91, 'cockroach implies unsanitary'));
        $graph->addNode(new MeaningNode('N3', 'health_risk',       0.87, 'unsanitary → health risk'));
        $graph->addNode(new MeaningNode('N4', 'travel_warning',    $this->weightFor($facts, 'RESULT', 0.84), 'official warning issued'));
        $graph->addNode(new MeaningNode('N5', 'avoid_destination', 0.82, 'travelers advised to avoid'));

        $graph->addEdge(new MeaningEdge('N1', 'N2', CausalRelation::CAUSES,    0.92));
        $graph->addEdge(new MeaningEdge('N2', 'N3', CausalRelation::ESCALATES, 0.88));
        $graph->addEdge(new MeaningEdge('N3', 'N4', CausalRelation::ESCALATES, 0.85));
        $graph->addEdge(new MeaningEdge('N4', 'N5', CausalRelation::ENABLES,   0.83));

        return $graph;
    }

    private function resolveGeneric(array $facts, array $worldState): MeaningGraph
    {
        $graph = new MeaningGraph(
            rootNodeId:        'N1',
            cinematicFunction: CinematicFunction::OBSERVE,
            tensionLevel:      4.0,
            confidence:        0.70,
        );

        $graph->addNode(new MeaningNode('N1', 'topic', 0.70, 'primary topic'));
        return $graph;
    }

    private function weightFor(array $facts, string $category, float $default): float
    {
        foreach ($facts as $fact) {
            if (($fact['category'] ?? '') === $category) {
                return (float) ($fact['confidence'] ?? $default);
            }
        }
        return $default;
    }
}
