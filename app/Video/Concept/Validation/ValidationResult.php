<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class ValidationResult
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(
        public readonly array $errors = [],
    ) {
    }

    public static function valid(): self
    {
        return new self();
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self($errors);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return list<array{
     *     code:string,
     *     path:string,
     *     message:string,
     *     expected:mixed,
     *     actual:mixed
     * }>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ValidationError $error): array =>
                $error->toArray(),
            $this->errors,
        );
    }

    /**
     * @param list<ValidationResult> $results
     */
    public static function merge(array $results): self
    {
        $errors = [];

        foreach ($results as $result) {
            foreach ($result->errors as $error) {
                $errors[] = $error;
            }
        }

        return new self($errors);
    }
}
