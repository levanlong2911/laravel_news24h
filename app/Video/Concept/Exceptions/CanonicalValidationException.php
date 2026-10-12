<?php

declare(strict_types=1);

namespace App\Video\Concept\Exceptions;

use App\Video\Concept\Validation\ValidationError;
use RuntimeException;

final class CanonicalValidationException extends RuntimeException
{
    /**
     * @param  list<ValidationError>  $errors
     */
    public function __construct(
        private readonly array $errors,
        private readonly ?string $failedRawJson = null,
        string $message = 'Canonical design validation failed.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
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
    public function errorPayload(): array
    {
        return array_map(
            static fn (ValidationError $error): array =>
                $error->toArray(),
            $this->errors,
        );
    }

    public function failedRawJson(): ?string
    {
        return $this->failedRawJson;
    }
}
