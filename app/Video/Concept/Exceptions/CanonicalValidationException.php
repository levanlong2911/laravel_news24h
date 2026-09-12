<?php

declare(strict_types=1);

namespace App\Video\Concept\Exceptions;

use App\Video\Concept\Processing\ValidationStageReport;
use App\Video\Concept\Validation\ValidationError;
use RuntimeException;

final class CanonicalValidationException extends RuntimeException
{
    /**
     * @param  list<ValidationError>  $errors
     * @param  list<ValidationStageReport>  $validationReports
     */
    public function __construct(
        private readonly array $errors,
        private readonly ?string $failedRawJson = null,
        string $message = 'Canonical design validation failed.',
        private readonly array $validationReports = [],
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

    /**
     * Cac chang §10.4 da chay TRUOC khi hong, ke ca chang hong.
     *
     * Duong thanh cong tra bao cao qua ProcessedCanonicalConcept; duong
     * that bai chi con exception de mang chung. Thieu no thi
     * canonical_validation_runs chi biet lan chay nao thanh cong, va lan
     * hong — dung luc can biet nhat — khong con dau vet chang nao.
     *
     * @return list<ValidationStageReport>
     */
    public function validationReports(): array
    {
        return $this->validationReports;
    }
}
