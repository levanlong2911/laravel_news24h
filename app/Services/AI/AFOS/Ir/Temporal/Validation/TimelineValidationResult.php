<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

final class TimelineValidationResult
{
    /** @param ValidationError[] $errors */
    public function __construct(private readonly array $errors) {}

    public static function ok(): self
    {
        return new self([]);
    }

    // ── Validity ──────────────────────────────────────────────────────────────

    /**
     * True when there are no ERROR-level issues.
     * Warnings (layer conflicts, soft temporal violations) do not invalidate.
     */
    public function isValid(): bool
    {
        return $this->issuesAtOrAbove(ValidationSeverity::Error) === [];
    }

    /** True when there are no issues at all (errors or warnings). */
    public function isClean(): bool
    {
        return $this->errors === [];
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /** @return ValidationError[] All issues regardless of severity. */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return string[] All issue messages regardless of severity. */
    public function errorMessages(): array
    {
        return array_map(fn(ValidationError $e) => $e->message(), $this->errors);
    }

    /**
     * Returns only issues of the given exact severity.
     * @return ValidationError[]
     */
    public function errorsOfSeverity(ValidationSeverity $severity): array
    {
        return array_values(array_filter($this->errors, fn($e) => $e->severity() === $severity));
    }

    /**
     * Returns issues at or above the given severity level.
     * @return ValidationError[]
     */
    public function issuesAtOrAbove(ValidationSeverity $min): array
    {
        return array_values(array_filter($this->errors, fn($e) => $e->severity()->isAtLeast($min)));
    }

    /**
     * Returns only errors of the given class.
     * @template T of ValidationError
     * @param class-string<T> $class
     * @return T[]
     */
    public function errorsOfType(string $class): array
    {
        return array_values(array_filter($this->errors, fn($e) => $e instanceof $class));
    }

    public function merge(self $other): self
    {
        return new self(array_merge($this->errors, $other->errors));
    }
}
