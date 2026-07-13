<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompt;

interface NodeWeightProvider
{
    public function weightFor(string $nodeId): float;
}
