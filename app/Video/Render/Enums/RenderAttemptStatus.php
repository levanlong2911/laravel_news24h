<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderAttemptStatus: string
{
    case CREATED = 'created';
    case PREPARING = 'preparing';
    case SUBMITTING = 'submitting';
    case SUBMITTED = 'submitted';
    case SUCCEEDED = 'succeeded';
    case RETRYABLE_FAILED = 'retryable_failed';
    case PERMANENT_FAILED = 'permanent_failed';
    case AMBIGUOUS = 'ambiguous';
    case ABANDONED = 'abandoned';
}

