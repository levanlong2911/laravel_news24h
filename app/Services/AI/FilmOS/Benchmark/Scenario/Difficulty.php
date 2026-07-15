<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

/** How hard a scenario is for a video provider. */
enum Difficulty: string
{
    case EASY    = 'easy';
    case MEDIUM  = 'medium';
    case HARD    = 'hard';
    case EXTREME = 'extreme';
}
