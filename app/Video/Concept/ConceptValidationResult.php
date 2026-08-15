<?php

namespace App\Video\Concept;

final class ConceptValidationResult
{
    /**
     * @param  list<string>  $fatalViolations
     * @param  list<ConceptWarning>  $warnings
     */
    public function __construct(
        public readonly array $fatalViolations,
        public readonly array $warnings,
    ) {}

    public function isFatal(): bool
    {
        return $this->fatalViolations !== [];
    }

    /** @return list<array{code: string, field: string, actual: int, recommended: int}> */
    public function warningsToArray(): array
    {
        return ConceptWarning::listToArray($this->warnings);
    }
}
