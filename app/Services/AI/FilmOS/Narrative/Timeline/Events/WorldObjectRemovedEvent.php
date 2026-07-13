<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class WorldObjectRemovedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string $eventId,
        private readonly string $aggregateId,  // "object:{objectId}"
        private readonly int    $shotOrdinal,
        private readonly int    $occurredAt,
        public readonly string  $objectId,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
