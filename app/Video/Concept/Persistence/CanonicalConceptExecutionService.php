<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Hashing\EffectiveConceptSchemaHasher;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use Throwable;

final class CanonicalConceptExecutionService
{
    public function __construct(
        private readonly CanonicalConceptRevisionService $revisions,
        private readonly CanonicalConceptPersistenceService $persistence,
        private readonly CanonicalFailureService $failures,
        private readonly BuildCanonicalConcept $builder,
        private readonly EffectiveConceptSchemaBuilder $schemaBuilder,
        private readonly EffectiveConceptSchemaHasher $schemaHasher,
        private readonly CanonicalStateTransitionService $states,
        private readonly CanonicalCheckpointService $checkpoints,
        private readonly CanonicalAttemptWriter $attempts,
        private readonly CanonicalRepairClaimService $repairClaims,
        private readonly CanonicalValidationRecorder $validations,
        private readonly CanonicalConceptExecutionCursor $cursor,
    ) {}

    public function create(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
        string $reason = 'initial',
        ?string $parentRevisionId = null,
    ): CanonicalConceptRevision {
        return $this->revisions->create(
            $projectId,
            $sessionId,
            $input,
            $reason,
            $parentRevisionId,
        );
    }

    public function execute(
        CanonicalConceptRevision $revision,
        ConceptInput $input,
    ): PersistedCanonicalConcept {
        try {
            $lifecycle = $this->lifecycle(
                revision: $revision,
                input: $input,
            );

            $resume = $this->cursor->resumableRawOutput($revision);

            $frozen = $this->builder
                ->withLifecycle($lifecycle)
                ->build(
                    input: $input,
                    revision: (int) $revision->revision,
                    resumeRawJson: $resume?->raw_output,
                    repairAllowed: $resume?->attempt_type
                        !== CanonicalAttemptType::REPAIR,
                );

            $current = $revision->fresh();

            return $this->persistence->freeze(
                $current,
                $frozen,
                ((int) $current->repair_count) > 0
                    ? DecisionOrigin::REPAIRED
                    : DecisionOrigin::GENERATED,
            );
        } catch (Throwable $e) {
            if ($this->isTerminal($e)) {
                $this->failures->fail(
                    $revision->id,
                    $this->failureCode($e),
                    $e->getMessage(),
                );
            }

            throw $e;
        }
    }

    private function lifecycle(
        CanonicalConceptRevision $revision,
        ConceptInput $input,
    ): PersistentCanonicalConceptLifecycle {
        $effective = $this->schemaBuilder->build($input->profile);
        $schemaHash = $this->schemaHasher->hash($effective);

        $revision->forceFill([
            'effective_schema_hash' => $schemaHash,
        ])->save();

        return new PersistentCanonicalConceptLifecycle(
            revisionId: $revision->id,
            states: $this->states,
            checkpoints: $this->checkpoints,
            attempts: $this->attempts,
            repairClaims: $this->repairClaims,
            validations: $this->validations,
            provider: (string) config('canonical_concept.provider', 'anthropic'),
            model: (string) $revision->concept_model,
            promptVersion: (string) $revision->concept_prompt_version,
            inputHash: (string) $revision->concept_input_hash,
            schemaHash: $schemaHash,
        );
    }

    /**
     * Loi nao ket thuc revision, loi nao chi ket thuc lan chay.
     *
     * Terminal = thiet ke that su khong dat: validation truot sau khi da
     * dung het suat sua, hoac model tu choi tra loi. Moi thu con lai —
     * timeout, 429, 5xx, dut ket noi — la su co van chuyen.
     */
    private function isTerminal(Throwable $e): bool
    {
        return $e instanceof CanonicalValidationException
            || $e instanceof AnthropicRefusalException;
    }

    private function failureCode(Throwable $e): string
    {
        return $e instanceof CanonicalValidationException
            ? 'canonical_validation_failed'
            : 'canonical_concept_failed';
    }
}
