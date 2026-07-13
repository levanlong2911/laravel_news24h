<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

final class PromptRuleEngine
{
    /** @param PromptRule[] $rules */
    public function __construct(private readonly array $rules) {}

    public function apply(PromptGraph $graph): PromptGraph
    {
        foreach ($this->rules as $rule) {
            if ($rule->applies($graph)) {
                $graph = $rule->apply($graph);
            }
        }
        return $graph;
    }
}
