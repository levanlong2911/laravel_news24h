<?php

declare(strict_types=1);

namespace App\Video\Concept\Freeze;

final class FrozenCanonicalConceptMetadata
{
    public function __construct(
        public readonly string $canonicalSchemaVersion,
        public readonly string $profileKey,
        public readonly string $profileVersion,
        public readonly string $effectiveSchemaHash,
        public readonly string $semanticValidatorVersion,
        public readonly string $normalizerVersion,
        public readonly string $canonicalizerVersion,
        public readonly string $conceptModel,
        public readonly string $conceptPromptVersion,
    ) {}

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'canonical_schema_version' => $this->canonicalSchemaVersion,

            'profile_key' => $this->profileKey,

            'profile_version' => $this->profileVersion,

            'effective_schema_hash' => $this->effectiveSchemaHash,

            'semantic_validator_version' => $this->semanticValidatorVersion,

            'normalizer_version' => $this->normalizerVersion,

            'canonicalizer_version' => $this->canonicalizerVersion,

            'concept_model' => $this->conceptModel,

            'concept_prompt_version' => $this->conceptPromptVersion,
        ];
    }
}
