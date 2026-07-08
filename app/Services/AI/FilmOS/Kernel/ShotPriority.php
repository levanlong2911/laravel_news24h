<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel;

enum ShotPriority: string
{
    case CRITICAL  = 'critical';
    case IMPORTANT = 'important';
    case FILLER    = 'filler';
}
