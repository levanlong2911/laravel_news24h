<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

enum SceneNodeType: string
{
    case CAMERA     = 'camera';
    case SUBJECT    = 'subject';
    case BACKGROUND = 'background';
    case LIGHT      = 'light';
}
