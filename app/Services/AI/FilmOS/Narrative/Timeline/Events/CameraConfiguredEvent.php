<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class CameraConfiguredEvent implements SemanticEvent
{
    public function __construct(
        private readonly string              $eventId,      // ULID
        private readonly string              $aggregateId,  // "camera:shot_{ordinal}"
        private readonly int                 $shotOrdinal,
        private readonly int                 $occurredAt,
        public readonly CameraConfiguration  $camera,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
