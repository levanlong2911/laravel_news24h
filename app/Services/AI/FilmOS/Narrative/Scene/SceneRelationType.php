<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

enum SceneRelationType: string
{
    case TARGETS    = 'targets';     // camera or character focuses on another node
    case IN_FRAME   = 'in_frame';    // node appears within the active frame
    case ADJACENT   = 'adjacent';    // nodes are spatially close (generic proximity)
    case BACKGROUND = 'background';  // node is present but visually secondary
}
