<?php

declare(strict_types=1);

namespace App\Video\Concept\Orchestration;

use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\ConceptInput;

final class CanonicalConceptOrchestrator
{
    public function __construct(
        private readonly BuildCanonicalConcept $builder,
    ) {}

    public function run(
        CanonicalConceptRequest $request
    ): CanonicalConceptResult {
        /*
         * -----------------------------------------
         * STEP 1
         * -----------------------------------------
         *
         * Chuyen application request
         * thanh semantic ConceptInput.
         */
        $input =
            new ConceptInput(
                objectType: $request->objectType,

                inspiration: $request->inspiration,

                profile: $request->profile,

                projectRequirements: $request->projectRequirements,
            );

        /*
         * -----------------------------------------
         * STEP 2
         * -----------------------------------------
         *
         * BuildCanonicalConcept chiu trach nhiem:
         *
         * Designer
         * -> validation
         * -> optional repair ONCE
         * -> normalization
         * -> re-validation
         * -> hash
         * -> freeze
         */
        $frozen =
            $this->builder
                ->build(
                    input: $input,

                    revision: $request->revision,
                );

        /*
         * -----------------------------------------
         * STEP 3
         * -----------------------------------------
         *
         * Tra application-level result.
         */
        return new CanonicalConceptResult(
            frozen: $frozen,

            objectType: $request->objectType,

            profileKey: $request->profile->key,

            profileVersion: $request->profile->version,
        );
    }
}
