<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;

final class WorldFactAssertedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string    $eventId,
        private readonly string    $aggregateId,  // "world_fact:{key}"
        private readonly int       $shotOrdinal,  // -1 = world baseline
        private readonly int       $occurredAt,
        public readonly WorldFact  $fact,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
