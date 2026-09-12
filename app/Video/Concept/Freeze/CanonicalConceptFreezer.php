<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Normalize\CanonicalDesignSpecNormalizer;
use App\Video\Concept\Processing\ProcessedCanonicalConcept;
use App\Video\Concept\Serialization\SchemaAwareCanonicalSerializer;
use App\Video\Concept\Support\Clock;
use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use InvalidArgumentException;

final class CanonicalConceptFreezer
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $designHasher,
        private readonly EffectiveConceptSchemaHasher $schemaHasher,
        private readonly CategorySemanticValidatorRegistry $semanticValidators,
        private readonly Clock $clock,
        private readonly string $conceptModel,
        private readonly string $conceptPromptVersion,
    ) {}

    public function freeze(
        ProcessedCanonicalConcept $processed,
        int $revision,
    ): FrozenCanonicalConcept {
        if ($revision < 1) {
            throw new InvalidArgumentException(
                'revision must be >= 1.'
            );
        }

        /*
         * Validated bytes = hashed bytes.
         */
        $designHash =
            $this->designHasher
                ->hash(
                    $processed->canonicalJson
                );

        $effectiveSchemaHash =
            $this->schemaHasher
                ->hash(
                    $processed->effectiveSchema
                );

        /*
         * forProfileKey() chu khong forProfile(): Freezer chi co identity cua
         * profile, khong co schemaPath lan inspectionAspects — dung mot
         * CategoryCreativeProfile gia chi de tra cuu la sai.
         */
        $categoryValidator =
            $this->semanticValidators
                ->forProfileKey(
                    $processed
                        ->effectiveSchema
                        ->profileKey
                );

        $metadata =
            new FrozenCanonicalConceptMetadata(
                canonicalSchemaVersion: $processed
                    ->effectiveSchema
                    ->coreVersion,

                profileKey: $processed
                    ->effectiveSchema
                    ->profileKey,

                profileVersion: $processed
                    ->effectiveSchema
                    ->profileVersion,

                effectiveSchemaHash: $effectiveSchemaHash,

                semanticValidatorVersion: $categoryValidator
                    ->version(),

                normalizerVersion: CanonicalDesignSpecNormalizer::VERSION,

                canonicalizerVersion: SchemaAwareCanonicalSerializer::VERSION,

                conceptModel: $this->conceptModel,

                conceptPromptVersion: $this->conceptPromptVersion,
            );

        return new FrozenCanonicalConcept(
            spec: $processed->spec,

            canonicalJson: $processed->canonicalJson,

            hash: $designHash,

            revision: $revision,

            frozenAt: $this->clock->now(),

            metadata: $metadata,
        );
    }
}
