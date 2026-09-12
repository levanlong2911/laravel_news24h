<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;

final class AircraftSemanticValidator implements CategorySemanticValidator
{
    public function profileKey(): string
    {
        return 'aircraft';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input,
    ): ValidationResult {
        $errors = [];

        $this->validateEngineConfiguration($spec, $errors);

        return ValidationResult::invalid($errors);
    }

    /**
     * @param  list<ValidationError>  $errors
     */
    private function validateEngineConfiguration(
        CanonicalDesignSpec $spec,
        array &$errors,
    ): void {
        $config = $spec->permanentGeometry['engine_configuration'] ?? null;

        if (! is_array($config)) {
            return;
        }

        $count = $config['count'] ?? null;
        $mounting = $config['mounting'] ?? null;

        if (
            $count === 0
            && is_string($mounting)
            && trim($mounting) !== ''
        ) {
            $errors[] = new ValidationError(
                code: 'aircraft.engine.zero_count_with_mounting',
                path: 'permanent_geometry.engine_configuration',
                message: 'Aircraft engine count is zero but engine mounting geometry is declared.',
                expected: 'no engine mounting when count=0',
                actual: $mounting,
            );
        }
    }
}
