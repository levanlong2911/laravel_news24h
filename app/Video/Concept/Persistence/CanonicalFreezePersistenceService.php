<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Hashing\CanonicalDesignSpecHasher;
use App\Video\Concept\Persistence\DTO\CanonicalFreezeRecord;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Ledger\DecisionLedgerWriter;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CanonicalFreezePersistenceService
{
    public function __construct(
        private readonly CanonicalDesignSpecHasher $hasher,
        private readonly DecisionLedgerWriter $ledger,
        private readonly CanonicalEventWriter $events,
    ) {}

    public function freeze(
        string $revisionId,
        FrozenCanonicalConcept $frozen,
        DecisionOrigin $origin,
    ): CanonicalFreezeRecord {
        return DB::transaction(function () use ($revisionId, $frozen, $origin): CanonicalFreezeRecord {
            /** @var CanonicalConceptRevision $revision */
            $revision = CanonicalConceptRevision::query()
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($revision->status === CanonicalConceptStatus::FROZEN) {
                if ($revision->canonical_hash !== $frozen->hash) {
                    throw new RuntimeException(
                        'Frozen canonical revision cannot be replayed with different bytes.'
                    );
                }

                return new CanonicalFreezeRecord($frozen, true);
            }

            if ($revision->status !== CanonicalConceptStatus::FREEZING) {
                throw new RuntimeException(
                    'Canonical revision must be in freezing state before freeze.'
                );
            }

            $computed = $this->hasher->hash($frozen->canonicalJson);

            if (! hash_equals($computed, $frozen->hash)) {
                throw new RuntimeException(
                    'Frozen canonical hash mismatch.'
                );
            }

            $this->ledger->write($revision, $frozen->spec, $origin);

            $revision->forceFill([
                'status' => CanonicalConceptStatus::FROZEN,
                'canonical_json' => $frozen->canonicalJson,
                'canonical_hash' => $frozen->hash,
                'frozen_at' => $frozen->frozenAt,
                'canonical_schema_version' => $frozen->metadata->canonicalSchemaVersion,
                'profile_key' => $frozen->metadata->profileKey,
                'profile_version' => $frozen->metadata->profileVersion,
                'effective_schema_hash' => $frozen->metadata->effectiveSchemaHash,
                'semantic_validator_version' => $frozen->metadata->semanticValidatorVersion,
                'normalizer_version' => $frozen->metadata->normalizerVersion,
                'canonicalizer_version' => $frozen->metadata->canonicalizerVersion,
                'concept_model' => $frozen->metadata->conceptModel,
                'concept_prompt_version' => $frozen->metadata->conceptPromptVersion,
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();

            $this->events->append(
                revision: $revision,
                type: CanonicalEventType::FROZEN,
                from: CanonicalConceptStatus::FREEZING,
                to: CanonicalConceptStatus::FROZEN,
                metadata: [
                    'canonical_hash' => $frozen->hash,
                    'effective_schema_hash' => $frozen->metadata->effectiveSchemaHash,
                    'revision' => $frozen->revision,
                ],
                eventKey: 'frozen:'.$frozen->hash,
            );

            return new CanonicalFreezeRecord($frozen, false);
        });
    }
}
