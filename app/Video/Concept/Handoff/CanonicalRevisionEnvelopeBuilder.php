<?php

declare(strict_types=1);

namespace App\Video\Concept\Handoff;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use RuntimeException;

final class CanonicalRevisionEnvelopeBuilder
{
    /**
     * @param  array<string, mixed>  $assetRequest
     * @return array{
     *     canonical_revision: array{
     *         metadata: array<string, mixed>,
     *         canonical_json: string
     *     },
     *     asset_request: array<string, mixed>
     * }
     */
    public function build(
        CanonicalConceptRevision $revision,
        array $assetRequest,
    ): array {
        $this->assertFrozen($revision);

        $canonicalJson = (string) $revision->canonical_json;

        $this->assertIntegrity($revision, $canonicalJson);

        return $this->envelope(
            metadata: [
                'revision_id' => (string) $revision->id,
                'project_id' => (string) $revision->video_project_id,
                'revision' => (int) $revision->revision,
                'canonical_hash' => (string) $revision->canonical_hash,
                'schema_version' => (string) $revision->canonical_schema_version,
                'profile_key' => (string) $revision->profile_key,
                'profile_version' => (string) $revision->profile_version,
                'effective_schema_hash' => (string) $revision->effective_schema_hash,
                'normalizer_version' => (string) $revision->normalizer_version,
                'canonicalizer_version' => (string) $revision->canonicalizer_version,
            ],
            canonicalJson: $canonicalJson,
            assetRequest: $assetRequest,
        );
    }

    /**
     * @param  array<string, mixed>  $assetRequest
     * @return array<string, mixed>
     */
    public function buildCandidate(
        FrozenCanonicalConcept $frozen,
        string $revisionId,
        string $projectId,
        array $assetRequest,
    ): array {
        return $this->envelope(
            metadata: [
                'revision_id' => $revisionId,
                'project_id' => $projectId,
                'revision' => $frozen->revision,
                'canonical_hash' => $frozen->hash,
                'schema_version' => $frozen->metadata->canonicalSchemaVersion,
                'profile_key' => $frozen->metadata->profileKey,
                'profile_version' => $frozen->metadata->profileVersion,
                'effective_schema_hash' => $frozen->metadata->effectiveSchemaHash,
                'normalizer_version' => $frozen->metadata->normalizerVersion,
                'canonicalizer_version' => $frozen->metadata->canonicalizerVersion,
            ],
            canonicalJson: $frozen->canonicalJson,
            assetRequest: $assetRequest,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $assetRequest
     * @return array<string, mixed>
     */
    private function envelope(
        array $metadata,
        string $canonicalJson,
        array $assetRequest,
    ): array {
        return [
            'canonical_revision' => [
                'metadata' => $metadata,
                'canonical_json' => $canonicalJson,
            ],

            'asset_request' => $assetRequest,
        ];
    }

    private function assertFrozen(
        CanonicalConceptRevision $revision
    ): void {
        if (! $revision->isFrozen()) {
            throw new RuntimeException(
                sprintf(
                    'Only a frozen canonical revision may cross the Python boundary; revision %s is %s.',
                    $revision->id,
                    $revision->status->value,
                )
            );
        }
    }

    private function assertIntegrity(
        CanonicalConceptRevision $revision,
        string $canonicalJson,
    ): void {
        if ($canonicalJson === '') {
            throw new RuntimeException(
                'Frozen canonical revision has no canonical_json: '.$revision->id
            );
        }

        $declared = (string) $revision->canonical_hash;

        if (! hash_equals($declared, hash('sha256', $canonicalJson))) {
            throw new RuntimeException(
                'Canonical hash does not match the stored bytes for revision '.$revision->id
            );
        }

        foreach ([
            'effective_schema_hash' => $revision->effective_schema_hash,
            'normalizer_version' => $revision->normalizer_version,
            'canonicalizer_version' => $revision->canonicalizer_version,
        ] as $field => $value) {
            if ($value === null || $value === '') {
                throw new RuntimeException(
                    sprintf(
                        'Frozen canonical revision %s is missing %s.',
                        $revision->id,
                        $field,
                    )
                );
            }
        }
    }
}
