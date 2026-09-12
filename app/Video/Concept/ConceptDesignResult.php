<?php

namespace App\Video\Concept;

use App\Video\Concept\Canonical\CanonicalDesignSpec;

final class ConceptDesignResult
{
    public function __construct(
        public readonly CanonicalDesignSpec $concept,
        public readonly int $revision,
        public readonly string $hash,
        public readonly string $rawResponse = '',
    ) {}
}
