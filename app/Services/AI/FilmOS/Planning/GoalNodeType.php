<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

enum GoalNodeType: string
{
    case ROOT         = 'root';
    case INTERMEDIATE = 'intermediate';
    case LEAF         = 'leaf';
}
