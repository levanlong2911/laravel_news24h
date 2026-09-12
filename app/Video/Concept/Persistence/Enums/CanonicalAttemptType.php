<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalAttemptType: string
{
    case GENERATION = 'generation';
    case REPAIR = 'repair';
}
