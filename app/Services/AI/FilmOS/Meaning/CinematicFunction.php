<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

enum CinematicFunction: string
{
    case OBSERVE   = 'observe';
    case REVEAL    = 'reveal';
    case ECHO      = 'echo';
    case ESCALATE  = 'escalate';
    case ESTABLISH = 'establish';
    case RESOLVE   = 'resolve';
}
