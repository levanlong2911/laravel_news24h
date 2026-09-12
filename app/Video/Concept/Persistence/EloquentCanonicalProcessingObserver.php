<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

final class EloquentCanonicalProcessingObserver implements CanonicalProcessingObserver
{
    public function __construct(
        private readonly CanonicalStateTransitionService $states,
    ) {}

    public function transition(
        CanonicalConceptRevision $revision,
        CanonicalConceptStatus $to,
        CanonicalEventType $event,
        ?array $payload = null,
        ?string $eventKey = null,
    ): CanonicalConceptRevision {
        return $this->states->transition(
            revisionId: $revision->id,
            to: $to,
            eventType: $event,
            metadata: $payload,
            eventKey: $eventKey,
        );
    }
}
