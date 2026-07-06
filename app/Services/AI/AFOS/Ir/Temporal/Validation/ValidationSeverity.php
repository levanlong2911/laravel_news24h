<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

enum ValidationSeverity: int
{
    case Info    = 0;
    case Warning = 1;
    case Error   = 2;

    public function label(): string
    {
        return match ($this) {
            self::Info    => 'INFO',
            self::Warning => 'WARNING',
            self::Error   => 'ERROR',
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }
}
