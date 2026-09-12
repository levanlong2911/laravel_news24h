<?php

declare(strict_types=1);

namespace App\Video\Render\Enums;

enum RenderExecutionDisposition: string
{
    case RETRY_SAFE = 'retry_safe';
    case DO_NOT_RETRY = 'do_not_retry';
    case AMBIGUOUS = 'ambiguous';
}

