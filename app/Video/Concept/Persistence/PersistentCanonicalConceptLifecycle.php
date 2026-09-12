<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\CanonicalConceptLifecycle;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Persistence\DTO\AttemptUsage;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use LogicException;

final class PersistentCanonicalConceptLifecycle implements CanonicalConceptLifecycle
{
    private ?CanonicalConceptAttempt $attempt = null;

    public function __construct(
        private readonly string $revisionId,
        private readonly CanonicalStateTransitionService $states,
        private readonly CanonicalCheckpointService $checkpoints,
        private readonly CanonicalAttemptWriter $attempts,
        private readonly CanonicalRepairClaimService $repairClaims,
        private readonly CanonicalValidationRecorder $validations,
        private readonly string $provider,
        private readonly string $model,
        private readonly string $promptVersion,
        private readonly string $inputHash,
        private readonly string $schemaHash,
    ) {}

    public function generationStarted(): void
    {
        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::PENDING) {
            $revision = $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::GENERATING,
                CanonicalEventType::GENERATION_STARTED,
                eventKey: 'generation:start',
            );
        }

        $this->attempt = $this->attempts->start(
            revision: $revision,
            type: CanonicalAttemptType::GENERATION,
            number: 1,
            provider: $this->provider,
            model: $this->model,
            promptVersion: $this->promptVersion,
            inputHash: $this->inputHash,
            schemaHash: $this->schemaHash,
        );
    }

    public function generationCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {
        if ($this->attempt === null) {
            throw new LogicException(
                'Generation attempt was not started.'
            );
        }

        $this->attempts->succeed(
            $this->attempt,
            $response->rawText,
            $this->usage($response),
        );

        $this->checkpoints->rawOutput($this->revisionId, $response->rawText);

        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::GENERATING) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::GENERATED,
                CanonicalEventType::GENERATION_SUCCEEDED,
                eventKey: 'generation:succeeded',
            );
        }
    }

    public function validationStarted(): void
    {
        $revision = $this->revision();

        if (in_array($revision->status, [CanonicalConceptStatus::GENERATED, CanonicalConceptStatus::REPAIRED], true)) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::VALIDATING,
                CanonicalEventType::VALIDATION_STARTED,
                eventKey: 'validation:start:'.$revision->repair_count,
            );
        }
    }

    /**
     * Ha canh bao cao §10.53 xuong canonical_validation_runs.
     *
     * Gan vao attempt dang mo, nen doc nguoc lai biet duoc chang nao thuoc
     * lan sinh dau va chang nao thuoc lan sua — hai lan chay cung mot bo
     * chang tren hai tai lieu khac nhau.
     *
     * @param  list<\App\Video\Concept\Processing\ValidationStageReport>  $reports
     */
    public function validationCompleted(
        array $reports
    ): void {
        $this->validations->recordReports(
            revision: $this->revision(),
            attempt: $this->attempt,
            reports: $reports,
        );
    }

    public function validationFailed(
        string $rawJson,
        array $errors
    ): void {
        $this->checkpoints->rawOutput($this->revisionId, $rawJson);
        $this->checkpoints->validationFailure($this->revisionId, $errors);

        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::VALIDATING) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::VALIDATION_FAILED,
                CanonicalEventType::VALIDATION_FAILED,
                metadata: ['error_count' => count($errors)],
                eventKey: 'validation:failed:'.$revision->repair_count,
            );
        }
    }

    public function repairStarted(): void
    {
        $revision = $this->repairClaims->claimRepair($this->revisionId);

        $this->states->transition(
            $revision->id,
            CanonicalConceptStatus::REPAIRING,
            CanonicalEventType::REPAIR_STARTED,
            eventKey: 'repair:start',
        );

        $this->attempt = $this->attempts->start(
            revision: $revision,
            type: CanonicalAttemptType::REPAIR,
            number: 2,
            provider: $this->provider,
            model: $this->model,
            promptVersion: $this->promptVersion,
            inputHash: $this->inputHash,
            schemaHash: $this->schemaHash,
        );
    }

    public function repairCompleted(
        AnthropicStructuredOutputResponse $response
    ): void {
        if ($this->attempt === null) {
            throw new LogicException(
                'Repair attempt was not started.'
            );
        }

        $this->attempts->succeed(
            $this->attempt,
            $response->rawText,
            $this->usage($response),
        );

        $this->checkpoints->rawOutput($this->revisionId, $response->rawText);

        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::REPAIRING) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::REPAIRED,
                CanonicalEventType::REPAIR_SUCCEEDED,
                eventKey: 'repair:succeeded',
            );
        }
    }

    public function normalizationStarted(): void
    {
        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::VALIDATING) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::NORMALIZING,
                CanonicalEventType::NORMALIZATION_STARTED,
                eventKey: 'normalization:start',
            );
        }
    }

    public function normalizationCompleted(string $canonicalJson): void
    {
        $this->checkpoints->clearValidationErrors($this->revisionId);

        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::NORMALIZING) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::NORMALIZED,
                CanonicalEventType::NORMALIZATION_COMPLETED,
                metadata: ['canonical_hash' => hash('sha256', $canonicalJson)],
                eventKey: 'normalization:completed',
            );
        }
    }

    public function freezingStarted(): void
    {
        $revision = $this->revision();

        if ($revision->status === CanonicalConceptStatus::NORMALIZED) {
            $this->states->transition(
                $revision->id,
                CanonicalConceptStatus::FREEZING,
                CanonicalEventType::FREEZE_STARTED,
                eventKey: 'freeze:start',
            );
        }
    }

    private function revision(): CanonicalConceptRevision
    {
        return CanonicalConceptRevision::query()
            ->whereKey($this->revisionId)
            ->firstOrFail();
    }

    private function usage(AnthropicStructuredOutputResponse $response): AttemptUsage
    {
        return new AttemptUsage(
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            providerRequestId: $response->requestId,
            stopReason: $response->stopReason,
        );
    }
}
