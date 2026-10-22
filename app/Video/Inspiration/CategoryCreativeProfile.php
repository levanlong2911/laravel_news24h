<?php

namespace App\Video\Inspiration;

use InvalidArgumentException;

final class CategoryCreativeProfile
{
    /**
     * @param  list<string>  $arcStages
     * @param  list<string>  $arcRequiredStages
     * @param  list<string>  $conceptAntipatterns
     * @param  list<string>  $conceptForbiddenTerms
     */
    public function __construct(
        public readonly string $key,
        public readonly string $conceptMission = '',
        public readonly array $arcStages = [],
        public readonly array $arcRequiredStages = [],
        public readonly array $conceptAntipatterns = [],
        public readonly array $conceptForbiddenTerms = [],
        public readonly string $version = '1.0',
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Creative profile key must not be empty.');
        }

        foreach (['concept_antipatterns' => $conceptAntipatterns, 'concept_forbidden_terms' => $conceptForbiddenTerms] as $name => $items) {
            foreach ($items as $item) {
                if (! is_string($item) || trim($item) === '') {
                    throw new InvalidArgumentException("Creative profile {$key} {$name} must contain only non-empty strings.");
                }
            }

            if (count($items) !== count(array_unique($items))) {
                throw new InvalidArgumentException("Creative profile {$key} {$name} must be unique.");
            }
        }
    }
}
