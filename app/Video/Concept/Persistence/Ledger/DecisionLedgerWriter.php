<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Ledger;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Models\CanonicalDecision;
use JsonException;
use LogicException;
use RuntimeException;

final class DecisionLedgerWriter
{
    public function __construct(
        private readonly CanonicalDecisionExtractor $extractor,
    ) {}

    public function write(
        CanonicalConceptRevision $revision,
        CanonicalDesignSpec $spec,
        DecisionOrigin $origin,
    ): void {
        /*
         * Ledger duoc ghi NGAY TRUOC freeze, trong cung transaction voi no
         * (§10.39). Sau khi da dong bang thi khong con gi de ghi: mot
         * revision frozen ma ledger doi duoc nghia la hai nguon su that
         * cho cung mot thiet ke.
         */
        if ($revision->isFrozen()) {
            throw new LogicException(
                'Cannot rewrite Decision Ledger '
                .'after revision is frozen.'
            );
        }

        if ($revision->decisions()->exists()) {
            throw new LogicException(
                'Canonical decision ledger is write-once per revision.'
            );
        }

        foreach ($this->extractor->extract($spec) as $ordinal => $decision) {
            CanonicalDecision::query()->create([
                'canonical_concept_revision_id' => $revision->id,
                'target_path' => $decision['target_path'],
                'decision_type' => $decision['decision_type'],
                'value' => $decision['value'],
                'provenance_origin' => $decision['provenance_origin'],
                'source_aspects' => $decision['source_aspects'] ?: null,
                'invariant_ids' => $decision['invariant_ids'] ?: null,
                'relationship_ids' => $decision['relationship_ids'] ?: null,
                'value_hash' => $this->hashValue($decision['value']),
                'ordinal' => $ordinal,
                'origin' => $origin,
            ]);
        }
    }

    /**
     * Van tay cua mot quyet dinh don le.
     *
     * Dung de so hai revision ma khong phai doc tung JSON. No KHONG phai
     * canonical hash va khong duoc dung thay: gia tri o day la mot manh da
     * trich ra tu DTO da normalize, con nguon su that van la
     * canonical_concept_revisions.canonical_json.
     */
    private function hashValue(
        mixed $value
    ): string {
        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Unable to hash Decision Ledger value.',
                previous: $e
            );
        }

        return hash('sha256', $json);
    }
}
