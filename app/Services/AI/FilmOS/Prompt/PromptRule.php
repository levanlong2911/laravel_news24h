<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

interface PromptRule
{
    public function applies(PromptGraph $graph): bool;

    public function apply(PromptGraph $graph): PromptGraph;
}
