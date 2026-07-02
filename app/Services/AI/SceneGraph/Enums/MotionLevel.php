<?php

namespace App\Services\AI\SceneGraph\Enums;

enum MotionLevel: string
{
    case HIGH   = 'high';
    case MEDIUM = 'medium';
    case LOW    = 'low';
    case NONE   = 'none';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::MEDIUM;
    }
}
