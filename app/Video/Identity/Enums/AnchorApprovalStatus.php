<?php

declare(strict_types=1);

namespace App\Video\Identity\Enums;

enum AnchorApprovalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
