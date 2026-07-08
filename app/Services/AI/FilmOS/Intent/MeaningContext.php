<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Intent;

use App\Services\AI\FilmOS\Meaning\CinematicFunction;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;

final class MeaningContext
{
    public function __construct(
        public readonly MeaningGraph       $graph,
        public readonly CinematicFunction  $function,
        public readonly float              $tensionLevel,
        public readonly float              $meaningConfidence,
    ) {}
}
