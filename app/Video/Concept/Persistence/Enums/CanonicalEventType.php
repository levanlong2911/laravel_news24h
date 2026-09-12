<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalEventType: string
{
    case REVISION_CREATED = 'revision_created';
    case GENERATION_STARTED = 'generation_started';
    case GENERATION_SUCCEEDED = 'generation_succeeded';
    case GENERATION_FAILED = 'generation_failed';
    case VALIDATION_STARTED = 'validation_started';
    case VALIDATION_PASSED = 'validation_passed';
    case VALIDATION_FAILED = 'validation_failed';
    case REPAIR_STARTED = 'repair_started';
    case REPAIR_SUCCEEDED = 'repair_succeeded';
    case REPAIR_FAILED = 'repair_failed';
    case NORMALIZATION_STARTED = 'normalization_started';
    case NORMALIZATION_COMPLETED = 'normalization_completed';
    case FREEZE_STARTED = 'freeze_started';
    case FROZEN = 'frozen';
    case FAILED = 'failed';
}
