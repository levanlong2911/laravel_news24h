<?php

namespace App\Video\Superyacht\Validation;

/**
 * Result of a schema check and/or invariant checks.
 */
final class ValidationReport
{
    /** @param array<int,Violation> $violations */
    public function __construct(public readonly array $violations = [])
    {
    }

    public function passes(): bool
    {
        return $this->violations === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /** @return array<int,string> */
    public function codes(): array
    {
        return array_values(array_unique(array_map(fn (Violation $v) => $v->code, $this->violations)));
    }

    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }

    /** @return array<int,string> */
    public function messages(): array
    {
        return array_map(fn (Violation $v) => (string) $v, $this->violations);
    }
}
