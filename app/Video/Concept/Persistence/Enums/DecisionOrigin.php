<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum DecisionOrigin: string
{
    case GENERATED = 'generated';
    case REPAIRED = 'repaired';
    case NORMALIZED = 'normalized';
    case SYSTEM = 'system';
}
