<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

final class CycleError extends ValidationError
{
    /** @param string[] $cyclePath */
    public function __construct(public readonly array $cyclePath) {}

    public function message(): string
    {
        return 'Cycle detected: ' . implode(' → ', $this->cyclePath);
    }
}
