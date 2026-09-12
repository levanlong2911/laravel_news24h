<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum QaStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
