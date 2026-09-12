<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Freeze\FrozenCanonicalConceptMetadata;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class FrozenCanonicalConceptHydrator
{
    public function hydrate(CanonicalConceptRevision $revision): FrozenCanonicalConcept
    {
        if ($revision->status !== CanonicalConceptStatus::FROZEN) {
            throw new RuntimeException(
                'Only frozen canonical revisions can be hydrated.'
            );
        }

        if ($revision->canonical_json === null || $revision->canonical_hash === null || $revision->frozen_at === null) {
            throw new RuntimeException(
                'Frozen canonical revision is missing frozen content.'
            );
        }

        if (hash('sha256', $revision->canonical_json) !== $revision->canonical_hash) {
            throw new RuntimeException(
                'Frozen canonical revision hash mismatch.'
            );
        }

        try {
            $data = json_decode($revision->canonical_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to decode frozen canonical JSON.',
                previous: $e,
            );
        }

        return new FrozenCanonicalConcept(
            spec: CanonicalDesignSpec::fromArray($data),
            canonicalJson: $revision->canonical_json,
            hash: $revision->canonical_hash,
            revision: (int) $revision->revision,
            frozenAt: DateTimeImmutable::createFromInterface($revision->frozen_at),
            metadata: new FrozenCanonicalConceptMetadata(
                canonicalSchemaVersion: (string) $revision->canonical_schema_version,
                profileKey: (string) $revision->profile_key,
                profileVersion: (string) $revision->profile_version,
                effectiveSchemaHash: (string) $revision->effective_schema_hash,
                semanticValidatorVersion: (string) $revision->semantic_validator_version,
                normalizerVersion: (string) $revision->normalizer_version,
                canonicalizerVersion: (string) $revision->canonicalizer_version,
                conceptModel: (string) $revision->concept_model,
                conceptPromptVersion: (string) $revision->concept_prompt_version,
            ),
        );
    }
}
