<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

enum TaskType: string
{
    case MEANING_RESOLUTION = 'meaning_resolution';
    case PLANNING           = 'planning';
    case COMPILATION        = 'compilation';
    case RENDER             = 'render';
    case EVALUATE           = 'evaluate';
    case LEARN              = 'learn';
    case ANALYTICS          = 'analytics';
}
