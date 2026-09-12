<?php

declare(strict_types=1);

namespace App\Video\Concept;

use App\Video\Inspiration\InspirationBrief;
use App\Video\Profiles\CategoryCreativeProfile;

final class ConceptInput
{
    /**
     * @param  array<string,mixed>  $projectRequirements
     */
    public function __construct(
        public readonly string $objectType,
        public readonly InspirationBrief $inspiration,
        public readonly CategoryCreativeProfile $profile,
        public readonly array $projectRequirements = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $conceptInput =
            $this->inspiration
                ->toConceptInput(
                    $this->profile
                );

        return [
            'object_type' => $this->objectType,

            'profile' => $this->profile->toArray(),

            'inspiration' => $conceptInput['inspiration'],

            'coverage' => $conceptInput['coverage'],

            'project_requirements' => $this->projectRequirements,
        ];
    }
}
