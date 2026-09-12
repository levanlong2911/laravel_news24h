<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

interface CategorySemanticValidator
{
    public function profileKey(): string;

    public function version(): string;

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult;
}
