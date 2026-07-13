<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

/**
 * A character's emotional state changes at a given shot.
 *
 * Absence of this event means the emotion PERSISTS — a character frightened
 * at shot 1 stays frightened until a later event says otherwise
 * (see CharacterMemory::emotionAt()).
 */
final class CharacterEmotionChangedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string           $eventId,      // ULID
        private readonly string           $aggregateId,  // "character:{characterId}"
        private readonly int              $shotOrdinal,
        private readonly int              $occurredAt,
        public readonly string            $characterId,
        public readonly CharacterEmotion  $emotion,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
