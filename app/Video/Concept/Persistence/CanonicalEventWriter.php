<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptEvent;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Support\Clock;

final class CanonicalEventWriter
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string,mixed>|null  $metadata
     */
    public function append(
        CanonicalConceptRevision $revision,
        CanonicalEventType $type,
        ?CanonicalConceptStatus $from = null,
        ?CanonicalConceptStatus $to = null,
        ?array $metadata = null,
        ?string $eventKey = null,
    ): CanonicalConceptEvent {
        $identity = [
            'canonical_concept_revision_id' => $revision->id,
            'event_key' => $eventKey,
        ];

        $values = [
            'event_type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'metadata' => $metadata,
            'occurred_at' => $this->clock->now(),
        ];

        if ($eventKey !== null) {
            return CanonicalConceptEvent::query()->firstOrCreate(
                $identity,
                $values
            );
        }

        return CanonicalConceptEvent::query()->create($identity + $values);
    }
}
