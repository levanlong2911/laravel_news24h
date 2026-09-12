<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalAttemptStatus: string
{
    case STARTED = 'started';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
