<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Enums\ValidationStage;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use App\Video\Concept\Validation\CanonicalCompilabilityValidator;

final class CanonicalConceptPersistenceService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalProcessingObserver $observer,
        private readonly CanonicalFreezePersistenceService $freezer,
        private readonly CanonicalCompilabilityValidator $compilability,
        private readonly CanonicalValidationRecorder $recorder,
    ) {}

    public function createRevision(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
        string $canonicalSchemaVersion,
        string $conceptModel,
        string $conceptPromptVersion,
    ): CanonicalConceptRevision {
        $revision = $this->repository->createRevision(
            $projectId,
            $sessionId,
            $input,
            $canonicalSchemaVersion,
            $conceptModel,
            $conceptPromptVersion,
        );

        $this->observer->transition(
            $revision,
            CanonicalConceptStatus::PENDING,
            CanonicalEventType::REVISION_CREATED,
            ['revision' => $revision->revision],
        );

        return $revision->refresh();
    }

    public function transition(
        CanonicalConceptRevision $revision,
        CanonicalConceptStatus $to,
        CanonicalEventType $event,
        ?array $payload = null,
        ?string $eventKey = null,
    ): CanonicalConceptRevision {
        return $this->observer->transition($revision, $to, $event, $payload, $eventKey);
    }

    public function freeze(
        CanonicalConceptRevision $revision,
        FrozenCanonicalConcept $frozen,
        DecisionOrigin $origin = DecisionOrigin::GENERATED,
    ): PersistedCanonicalConcept {
        $compilable = $this->compilability->validate(
            $frozen,
            (string) $revision->id,
            (string) $revision->video_project_id,
        );

        if ($compilable->fails()) {
            $this->recorder->record(
                revision: $revision,
                attempt: null,
                stage: ValidationStage::COMPILABILITY,
                result: $compilable,
                documentHash: hash('sha256', $frozen->canonicalJson),
                validatorVersion: 'compile-canonical-prompt-v1',
            );

            throw new CanonicalValidationException(
                errors: $compilable->errors,
                failedRawJson: $frozen->canonicalJson,
                message: 'Canonical revision cannot be compiled into a prompt.',
            );
        }

        $record = $this->freezer->freeze($revision->id, $frozen, $origin);

        return new PersistedCanonicalConcept(
            $revision->fresh(),
            $record->frozen,
            $record->reused,
        );
    }
}
