<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

interface SemanticEvent
{
    /** ULID — sortable chronologically, supports D5 causality chains (causedByEventId). */
    public function eventId(): string;

    /** Event schema version. Returns 1 until a breaking schema change occurs.
     *  See EventUpcaster for the migration pattern when a v2 is introduced. */
    public function version(): int;

    /** "type:name" format — stable identity, never a display name.
     *  Examples: "shot:shot_hook", "character:hero", "object:sword_01"
     *  Identity never changes even if the display name changes. */
    public function aggregateId(): string;

    /** 0-based position in the production. Used by project(?upToOrdinal). */
    public function shotOrdinal(): int;

    /** Unix timestamp of when the fact was recorded. */
    public function occurredAt(): int;
}
