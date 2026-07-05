<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * PipelineValidationResult — outcome of PipelineDefinition::validate().
 *
 * Reports DAG errors: stages reading IR that hasn't been produced yet,
 * or missing outputs required by downstream stages.
 *
 * Usage:
 *   $result = PipelineDefinition::standard()->validate();
 *   $result->assert();  // throws on failure
 */
final class PipelineValidationResult
{
    public readonly bool $valid;

    /** @param string[] $errors Human-readable DAG error messages. */
    public function __construct(public readonly array $errors = [])
    {
        $this->valid = empty($errors);
    }

    /**
     * Throw a RuntimeException if the pipeline has validation errors.
     * Suitable for use in factory methods and tests.
     */
    public function assert(): void
    {
        if ($this->valid) {
            return;
        }

        $lines = array_map(fn(string $e) => "  • {$e}", $this->errors);
        throw new \RuntimeException(
            "Pipeline DAG validation failed:\n" . implode("\n", $lines)
        );
    }
}
