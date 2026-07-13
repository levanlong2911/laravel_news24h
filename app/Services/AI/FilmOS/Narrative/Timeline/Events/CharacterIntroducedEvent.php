<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Character\CharacterProfile;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

/**
 * A character enters the story.
 *
 * INVARIANT: exactly one introduction per character per production.
 * The projection applies last-write-wins (a duplicate introduction silently
 * overwrites the profile), but a duplicate is a narrative anomaly — D5 QA
 * should flag it when scanning the raw timeline. Do NOT rely on
 * re-introduction as an "update profile" mechanism.
 */
final class CharacterIntroducedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string           $eventId,      // ULID
        private readonly string           $aggregateId,  // "character:{characterId}"
        private readonly int              $shotOrdinal,  // BASELINE (-1) or the shot the character first appears
        private readonly int              $occurredAt,
        public readonly CharacterProfile  $profile,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
