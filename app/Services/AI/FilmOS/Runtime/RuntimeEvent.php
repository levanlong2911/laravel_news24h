<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Runtime;

enum RuntimeEvent: string
{
    case SUBMITTED          = 'submitted';
    case POLLING            = 'polling';
    case COMPLETED          = 'completed';
    case FAILED             = 'failed';
    case TIMEOUT            = 'timeout';
    case DOWNLOAD_STARTED   = 'download_started';
    case DOWNLOAD_COMPLETED = 'download_completed';
}
