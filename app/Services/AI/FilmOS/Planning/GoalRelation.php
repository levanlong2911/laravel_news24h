<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

enum GoalRelation: string
{
    case REQUIRES  = 'requires';
    case SUPPORTS  = 'supports';
    case CONFLICTS = 'conflicts';
    case ENABLES   = 'enables';
}
