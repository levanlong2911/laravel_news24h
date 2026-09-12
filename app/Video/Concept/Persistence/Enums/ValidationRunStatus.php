<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum ValidationRunStatus: string
{
    case PASSED = 'passed';
    case FAILED = 'failed';
}
