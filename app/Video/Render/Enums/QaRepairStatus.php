<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum QaRepairStatus: string
{
    case PLANNED = 'planned';
    case DISPATCHED = 'dispatched';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
