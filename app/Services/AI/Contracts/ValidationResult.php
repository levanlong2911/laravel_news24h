<?php

namespace App\Services\AI\Contracts;

final class ValidationResult
{
    private function __construct(
        public readonly bool  $passed,
        /** @var array<array{field:string,expected:string,actual:mixed}> */
        public readonly array $errors,
    ) {}

    public static function pass(): self
    {
        return new self(true, []);
    }

    /** @param array<array{field:string,expected:string,actual:mixed}> $errors */
    public static function fail(array $errors): self
    {
        return new self(false, $errors);
    }

    public function toArray(): array
    {
        return $this->errors;
    }
}
