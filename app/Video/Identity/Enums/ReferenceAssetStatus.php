<?php

declare(strict_types=1);

namespace App\Video\Identity\Enums;

enum ReferenceAssetStatus: string
{
    case PLANNED = 'planned';
    case RENDERING = 'rendering';
    case QA_PENDING = 'qa_pending';
    case PASSED = 'passed';
    case FAILED = 'failed';
    case REVIEW = 'review';
}
