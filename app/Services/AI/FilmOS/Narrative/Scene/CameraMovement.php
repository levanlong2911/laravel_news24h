<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

enum CameraMovement: string
{
    case STATIC   = 'static';
    case PAN      = 'pan';
    case TILT     = 'tilt';
    case TRACKING = 'tracking';
    case DOLLY    = 'dolly';
    case ZOOM     = 'zoom';
    case HANDHELD = 'handheld';
}
