<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Validation\ValidationError;
use App\Video\Concept\Validation\ValidationResult;
use App\Video\Profiles\CategoryCreativeProfileResolver;

final class ProfileCompatibilityValidator
{
    public function __construct(
        private readonly CategoryCreativeProfileResolver $profileResolver,
    ) {}

    public function validate(ConceptInput $input): ValidationResult
    {
        $expected =
            $this->profileResolver
                ->resolve($input->objectType);

        if ($expected->key === $input->profile->key) {
            return ValidationResult::valid();
        }

        return ValidationResult::invalid([
            new ValidationError(
                code: 'profile.object_type_mismatch',
                path: 'profile.key',
                message: 'Object type resolves to a different category profile.',
                expected: $expected->key,
                actual: $input->profile->key,
            ),
        ]);
    }
}
