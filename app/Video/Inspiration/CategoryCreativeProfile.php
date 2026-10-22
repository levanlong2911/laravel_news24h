<?php

namespace App\Video\Inspiration;

use InvalidArgumentException;

final class CategoryCreativeProfile
{
    /**
     * @param  list<string>  $articlePatterns
     * @param  list<string>  $inspectionAspects
     * @param  list<string>  $excludedContextTypes
     * @param  list<string>  $arcStages
     * @param  list<string>  $arcRequiredStages
     * @param  list<string>  $conceptAntipatterns
     * @param  list<string>  $conceptForbiddenTerms
     */
    public function __construct(
        public readonly string $key,
        public readonly string $mission,
        public readonly array $articlePatterns,
        public readonly array $inspectionAspects,
        public readonly array $excludedContextTypes,
        public readonly string $conceptMission = '',
        public readonly array $arcStages = [],
        public readonly array $arcRequiredStages = [],
        public readonly array $conceptAntipatterns = [],
        public readonly array $conceptForbiddenTerms = [],
        public readonly string $version = '1.0',
    ) {
        foreach ([$key, $mission] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Creative profile key and mission must not be empty.');
            }
        }

        foreach ([
            'article_patterns' => $articlePatterns,
            'inspection_aspects' => $inspectionAspects,
            'excluded_context_types' => $excludedContextTypes,
        ] as $name => $values) {
            if ($values === [] || count($values) !== count(array_unique($values))) {
                throw new InvalidArgumentException("Creative profile {$name} must be non-empty and unique.");
            }

            foreach ($values as $value) {
                if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_]*\z/', $value) !== 1) {
                    throw new InvalidArgumentException("Creative profile {$name} contains an invalid key.");
                }
            }
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
