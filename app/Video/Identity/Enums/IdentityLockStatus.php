<?php

declare(strict_types=1);

namespace App\Video\Identity\Enums;

enum IdentityLockStatus: string
{
    case CANDIDATE = 'candidate';
    case FROZEN = 'frozen';
    case SUPERSEDED = 'superseded';
}
