<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

final class DuplicateIdError extends ValidationError
{
    public function __construct(public readonly string $id) {}

    public function message(): string
    {
        return "Duplicate event id: '{$this->id}'";
    }
}
