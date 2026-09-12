<?php

declare(strict_types=1);

namespace App\Video\Profiles\Validation;

use App\Video\Profiles\CategoryCreativeProfile;
use RuntimeException;

final class CategorySemanticValidatorRegistry
{
    /**
     * @var array<string,CategorySemanticValidator>
     */
    private array $validators = [];

    /**
     * @param  list<CategorySemanticValidator>  $validators
     */
    public function __construct(array $validators)
    {
        foreach ($validators as $validator) {
            $this->register($validator);
        }
    }

    public function register(CategorySemanticValidator $validator): void
    {
        $key = trim($validator->profileKey());

        if ($key === '') {
            throw new RuntimeException(
                'Category semantic validator profile key must not be empty.'
            );
        }

        if (isset($this->validators[$key])) {
            throw new RuntimeException(
                'Duplicate category semantic validator for profile: '
                .$key
            );
        }

        $this->validators[$key] = $validator;
    }

    public function forProfileKey(
        string $profileKey
    ): CategorySemanticValidator {
        $profileKey =
            trim(
                $profileKey
            );

        $validator =
            $this->validators[
                $profileKey
            ]
            ?? null;

        if ($validator === null) {
            throw new RuntimeException(
                'No semantic validator registered '
                .'for profile: '
                .$profileKey
            );
        }

        return $validator;
    }

    public function forProfile(
        CategoryCreativeProfile $profile
    ): CategorySemanticValidator {
        return $this->forProfileKey(
            $profile->key
        );
    }

    public function has(string $profileKey): bool
    {
        return isset($this->validators[trim($profileKey)]);
    }
}
