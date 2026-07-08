<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

interface MeaningResolver
{
    public function resolve(
        array  $facts,
        string $domain,
        array  $worldState = [],
    ): MeaningGraph;
}
