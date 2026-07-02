<?php

namespace App\Services\AI\SceneGraph\Enums;

enum Pacing: string
{
    case FAST    = 'fast';
    case DYNAMIC = 'dynamic';
    case UPBEAT  = 'upbeat';
    case MEDIUM  = 'medium';
    case SLOW    = 'slow';
    case MONTAGE = 'montage';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::MEDIUM;
    }
}
