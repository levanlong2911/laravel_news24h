<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Scenario;

/** Benchmark suite a scenario belongs to — the axis it primarily exercises. */
enum Suite: string
{
    case CAMERA  = 'camera';
    case EMOTION = 'emotion';
    case WORLD   = 'world';
    case MOTION  = 'motion';
}
