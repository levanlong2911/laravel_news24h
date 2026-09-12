<?php

declare(strict_types=1);

namespace App\Video\Concept\Validation;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptInput;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use App\Video\Profiles\Validation\ProfileCompatibilityValidator;

final class CanonicalDesignSpecValidator implements CanonicalSemanticValidator
{
    public function __construct(
        private readonly CanonicalCrossFieldValidator $crossFieldValidator,
        private readonly CanonicalProvenanceValidator $provenanceValidator,
        private readonly ProfileCompatibilityValidator $profileCompatibilityValidator,
        private readonly CategorySemanticValidatorRegistry $categoryValidatorRegistry,
    ) {
    }

    public function validate(
        CanonicalDesignSpec $spec,
        ConceptInput $input
    ): ValidationResult {
        $crossFieldErrors =
            $this->crossFieldValidator
                ->validate($spec);

        $provenanceErrors =
            $this->provenanceValidator
                ->validate(
                    $spec,
                    $input->inspiration
                );

        $profileCompatibility =
            $this->profileCompatibilityValidator
                ->validate($input);

        $categorySemanticErrors = [];

        if ($profileCompatibility->passes()) {
            $categorySemanticErrors =
                $this->categoryValidatorRegistry
                    ->forProfile($input->profile)
                    ->validate($spec, $input)
                    ->errors;
        }

        return new ValidationResult([
            ...$crossFieldErrors,
            ...$provenanceErrors,
            ...$profileCompatibility->errors,
            ...$categorySemanticErrors,
        ]);
    }

    public function validateOrFail(
        CanonicalDesignSpec $spec,
        ConceptInput $input
    ): void {
        $result = $this->validate(
            $spec,
            $input
        );

        if ($result->fails()) {
            throw new \App\Video\Concept\Exceptions\CanonicalValidationException(
                errors:
                    $result->errors,

                message:
                    'Canonical semantic validation failed.',
            );
        }
    }
}
