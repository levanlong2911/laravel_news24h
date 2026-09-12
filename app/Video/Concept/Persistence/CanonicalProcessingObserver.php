<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

interface CanonicalProcessingObserver
{
    /**
     * @param  array<string,mixed>|null  $payload
     */
    public function transition(
        CanonicalConceptRevision $revision,
        CanonicalConceptStatus $to,
        CanonicalEventType $event,
        ?array $payload = null,
        ?string $eventKey = null,
    ): CanonicalConceptRevision;
}
