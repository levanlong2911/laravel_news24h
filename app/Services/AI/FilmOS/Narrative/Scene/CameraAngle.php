<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

enum CameraAngle: string
{
    case EYE_LEVEL      = 'eye_level';
    case HIGH           = 'high';
    case LOW            = 'low';
    case DUTCH          = 'dutch';
    case BIRDS_EYE      = 'birds_eye';
    case WORMS_EYE      = 'worms_eye';
    case OVER_SHOULDER  = 'over_shoulder';
}
