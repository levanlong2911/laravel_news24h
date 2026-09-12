<?php

declare(strict_types=1);

namespace App\Video\Concept\Canonical\Enums;

enum InvariantSeverity: string
{
    case HARD = 'hard';
    case SOFT = 'soft';
}
