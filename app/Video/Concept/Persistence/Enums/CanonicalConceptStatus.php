<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Enums;

enum CanonicalConceptStatus: string
{
    case PENDING = 'pending';
    case GENERATING = 'generating';
    case GENERATED = 'generated';
    case VALIDATING = 'validating';
    case VALIDATION_FAILED = 'validation_failed';
    case REPAIRING = 'repairing';
    case REPAIRED = 'repaired';
    case NORMALIZING = 'normalizing';
    case NORMALIZED = 'normalized';
    case FREEZING = 'freezing';
    case FROZEN = 'frozen';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::FROZEN,
            self::FAILED => true,
            default => false,
        };
    }

    public function isMutable(): bool
    {
        return $this !== self::FROZEN;
    }
}
