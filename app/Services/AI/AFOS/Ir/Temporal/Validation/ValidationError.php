<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

abstract class ValidationError
{
    abstract public function message(): string;

    /**
     * Severity of this issue. Subclasses override to set their natural level.
     * Default is ERROR; warnings override to Warning, infos to Info.
     */
    public function severity(): ValidationSeverity
    {
        return ValidationSeverity::Error;
    }
}
