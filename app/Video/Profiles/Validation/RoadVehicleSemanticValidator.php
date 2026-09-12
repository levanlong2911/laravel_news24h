<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationResult;

final class RoadVehicleSemanticValidator implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'road_vehicle';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        return ValidationResult::valid();
    }
}
