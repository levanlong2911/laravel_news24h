<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;

final class WorldObjectPlacedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string      $eventId,      // ULID
        private readonly string      $aggregateId,  // "object:{objectId}"
        private readonly int         $shotOrdinal,  // -1 = world baseline (before shot 0)
        private readonly int         $occurredAt,
        public readonly WorldObject  $object,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
