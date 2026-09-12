<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

final class ValidationReport
{
    /**
     * @param  list<ValidationResult>  $results
     */
    public function __construct(
        public readonly array $results,
    ) {}

    public function result(): ValidationResult
    {
        $merged = ValidationResult::valid();

        foreach ($this->results as $result) {
            $merged = $merged->merge($result);
        }

        return $merged;
    }
}
