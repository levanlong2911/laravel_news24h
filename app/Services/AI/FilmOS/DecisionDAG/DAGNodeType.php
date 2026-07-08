<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

enum DAGNodeType: string
{
    case FACT        = 'fact';
    case MEANING     = 'meaning';
    case PLAN        = 'plan';
    case INTENT      = 'intent';
    case COMPILATION = 'compilation';
    case RENDER      = 'render';
    case REVIEW      = 'review';
    case CONSENSUS   = 'consensus';
    case LEARN       = 'learn';
}
