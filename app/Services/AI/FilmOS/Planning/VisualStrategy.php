<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

enum VisualStrategy: string
{
    case OBSERVATIONAL  = 'observational';
    case URGENT         = 'urgent';
    case CONTEMPLATIVE  = 'contemplative';
    case DYNAMIC        = 'dynamic';
    case DOCUMENTARY    = 'documentary';
}
