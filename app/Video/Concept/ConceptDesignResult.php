<?php

namespace App\Video\Concept;

final class ConceptDesignResult
{
    /** @param list<ConceptWarning> $warnings */
    public function __construct(
        public readonly CreativeConcept $concept,
        public readonly array $warnings,
        public readonly int $attempts,
        public readonly string $rawResponse = '',
    ) {}

    /** @return list<array{code: string, field: string, actual: int, recommended: int}> */
    public function warningsToArray(): array
    {
        return ConceptWarning::listToArray($this->warnings);
    }
}
