<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

use App\Services\AI\FilmOS\Render\RenderIR;

final class PromptLearningEngine
{
    /** @param NodeWeightProvider[] $weightProviders */
    public function __construct(private readonly array $weightProviders = []) {}

    public static function baseline(): self
    {
        return new self([]);
    }

    public function compile(PromptGraph $graph): RenderIR
    {
        $graph = $this->applyLearning($graph);

        $renderInstructions = [];
        $constraints        = [];
        $metadata           = [];

        foreach ($this->sortByWeight($graph->nodes()) as $node) {
            match ($node->namespace) {
                'constraint' => $constraints[$node->id]        = $node->value,
                'attribute'  => $metadata[$node->id]           = $node->value,
                default      => $renderInstructions[$node->id] = $node->value,
            };
        }

        return new RenderIR(
            traceId:            $graph->traceId,
            version:            1,
            shotId:             $graph->shotId,
            durationSeconds:    (int) ($constraints['duration'] ?? 5),
            renderInstructions: $renderInstructions,
            constraints:        $constraints,
            metadata:           $metadata,
        );
    }

    private function applyLearning(PromptGraph $graph): PromptGraph
    {
        if (empty($this->weightProviders)) {
            return $graph;
        }

        $learnableIds = ['camera', 'lighting', 'physics', 'style'];

        foreach ($graph->nodes() as $node) {
            if (!in_array($node->id, $learnableIds, true)) {
                continue;
            }
            $weight = $this->resolveWeight($node->id);
            if ($weight !== $node->weight) {
                $graph = $graph->withNode($node->withWeight($weight));
            }
        }

        return $graph;
    }

    private function resolveWeight(string $nodeId): float
    {
        $total = 0.0;
        foreach ($this->weightProviders as $provider) {
            $total += $provider->weightFor($nodeId);
        }
        return $total / count($this->weightProviders);
    }

    /** @param PromptNode[] $nodes @return PromptNode[] */
    private function sortByWeight(array $nodes): array
    {
        usort($nodes, fn (PromptNode $a, PromptNode $b) => $b->weight <=> $a->weight);
        return $nodes;
    }
}
