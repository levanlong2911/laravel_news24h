<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Story\EndingFrame;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

final class ShotPlannedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string       $eventId,      // ULID
        private readonly string       $aggregateId,  // "shot:{shotId}"
        private readonly int          $shotOrdinal,
        private readonly int          $occurredAt,
        public readonly string        $shotId,
        public readonly string        $goalType,
        public readonly string        $description,
        /** Beat is intrinsic to the shot at planning time — carried, never derived.
         *  Nullable additive field: pre-D1 events remain valid, version stays 1. */
        public readonly ?StoryBeat    $beat = null,
        /** Narrative outcome — same additive rule as $beat, version stays 1. */
        public readonly ?EndingFrame  $endingFrame = null,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
