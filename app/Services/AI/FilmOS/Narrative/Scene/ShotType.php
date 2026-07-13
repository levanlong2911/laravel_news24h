<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

enum ShotType: string
{
    case ESTABLISHING   = 'establishing';
    case WIDE           = 'wide';
    case MEDIUM         = 'medium';
    case CLOSE_UP       = 'close_up';
    case EXTREME_CLOSE_UP = 'extreme_close_up';
    case TWO_SHOT       = 'two_shot';
    case INSERT         = 'insert';
}
