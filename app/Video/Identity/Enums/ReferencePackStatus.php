<?php

declare(strict_types=1);

namespace App\Video\Identity\Enums;

enum ReferencePackStatus: string
{
    case PLANNED = 'planned';
    case RENDERING = 'rendering';
    case QA_PENDING = 'qa_pending';
    case REVIEW = 'review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
