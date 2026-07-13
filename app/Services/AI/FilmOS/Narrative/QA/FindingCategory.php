<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA;

/**
 * Which narrative domain a finding belongs to — lets consumers (UI, benchmark)
 * filter without parsing codes.
 */
enum FindingCategory: string
{
    case STORY     = 'story';      // D0
    case CHARACTER = 'character';  // D2
    case WORLD     = 'world';      // D3
    case SCENE     = 'scene';      // D4
    case CAMERA    = 'camera';     // D4 camera domain
}
