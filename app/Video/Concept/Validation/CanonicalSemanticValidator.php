<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;

interface CanonicalSemanticValidator
{
    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input
    ): ValidationResult;
}
