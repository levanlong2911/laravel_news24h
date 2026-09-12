<?php

declare(strict_types=1);

namespace App\Video\Concept\Orchestration;

use App\Video\Inspiration\InspirationBrief;
use App\Video\Profiles\CategoryCreativeProfile;
use InvalidArgumentException;

final class CanonicalConceptRequest
{
    /**
     * @param  array<string,mixed>  $projectRequirements
     */
    public function __construct(
        public readonly string $objectType,
        public readonly InspirationBrief $inspiration,
        public readonly CategoryCreativeProfile $profile,
        public readonly int $revision,
        public readonly array $projectRequirements = [],
    ) {
        $this->guard();
    }

    private function guard(): void
    {
        if (trim($this->objectType) === '') {
            throw new InvalidArgumentException(
                'Canonical concept objectType '
                .'must not be empty.'
            );
        }

        if ($this->revision < 1) {
            throw new InvalidArgumentException(
                'Canonical concept revision '
                .'must be >= 1.'
            );
        }

        if (trim($this->profile->key) === '') {
            throw new InvalidArgumentException(
                'Category creative profile key '
                .'must not be empty.'
            );
        }

        if (trim($this->profile->version) === '') {
            throw new InvalidArgumentException(
                'Category creative profile version '
                .'must not be empty.'
            );
        }
    }
}
