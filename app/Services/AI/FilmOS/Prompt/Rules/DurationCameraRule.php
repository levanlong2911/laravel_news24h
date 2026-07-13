<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt\Rules;

use App\Services\AI\FilmOS\Prompt\PromptGraph;
use App\Services\AI\FilmOS\Prompt\PromptNode;
use App\Services\AI\FilmOS\Prompt\PromptRule;

final class DurationCameraRule implements PromptRule
{
    public function applies(PromptGraph $graph): bool
    {
        $node = $graph->node('duration', 'constraint');
        return $node !== null && (float) $node->value > 8;
    }

    public function apply(PromptGraph $graph): PromptGraph
    {
        $existing = $graph->node('camera');
        $weight   = $existing !== null ? max($existing->weight, 1.5) : 1.5;

        return $graph->withNode(new PromptNode(
            id:        'camera',
            namespace: 'render',
            value:     'wide',
            weight:    $weight,
        ));
    }
}
