<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Freeze\FrozenCanonicalConcept;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Enums\DecisionOrigin;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;

final class CanonicalConceptPersistenceService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalProcessingObserver $observer,
        private readonly CanonicalFreezePersistenceService $freezer,
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
        // Chot "dong bang duoc thi phai dung duoc thanh prompt" da bi go cung voi
        // duong Python dung prompt. Concept van dong bang duoc, nhung khong con ai
        // chung minh no dung duoc thanh prompt truoc khi dong.
        $record = $this->freezer->freeze($revision->id, $frozen, $origin);

        return new PersistedCanonicalConcept(
            $revision->fresh(),
            $record->frozen,
            $record->reused,
        );
    }
}
