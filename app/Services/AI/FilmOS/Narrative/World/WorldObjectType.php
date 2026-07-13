<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

enum WorldObjectType: string
{
    case CHARACTER   = 'character';
    case PROP        = 'prop';
    case LOCATION    = 'location';
    case ENVIRONMENT = 'environment';
}
