<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

use App\Services\AI\FilmOS\Planning\PlanningIR;

final class PromptCompiler
{
    public function compile(PlanningIR $ir): PromptGraph
    {
        $graph = new PromptGraph(traceId: $ir->traceId, shotId: $ir->shotId);

        foreach ($ir->renderHints as $id => $value) {
            $graph = $graph->withNode(new PromptNode(id: $id, namespace: 'render', value: $value));
        }

        foreach ($ir->constraints as $id => $value) {
            $graph = $graph->withNode(new PromptNode(id: $id, namespace: 'constraint', value: $value));
        }

        foreach ($ir->attributes as $id => $value) {
            $graph = $graph->withNode(new PromptNode(id: $id, namespace: 'attribute', value: $value));
        }

        return $graph;
    }
}
