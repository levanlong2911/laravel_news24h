<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\WorldFactAssertedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\WorldObjectPlacedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\WorldObjectRemovedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use Illuminate\Support\Str;

/**
 * Translates world context (objects + facts) into SemanticEvents for the Timeline.
 *
 * The only class permitted to create WorldObject and WorldFact events.
 * Does not know Planning domain; does not know GoalNode.
 */
final class WorldEventFactory
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  WorldObject[]  $objects  objects to place in the world baseline
     * @param  WorldFact[]    $facts    facts to assert about the world baseline
     * @param  int            $ordinal  timeline position (default: BASELINE = before shot 0)
     * @return SemanticEvent[]
     */
    public function fromWorldContext(
        array $objects,
        array $facts,
        int   $ordinal = TimelineOrdinal::BASELINE,
    ): array {
        $events = [];

        foreach ($objects as $object) {
            $events[] = new WorldObjectPlacedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "object:{$object->id}",
                shotOrdinal: $ordinal,
                occurredAt:  $this->clock->now(),
                object:      $object,
            );
        }

        foreach ($facts as $fact) {
            $events[] = new WorldFactAssertedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "world_fact:{$fact->key}",
                shotOrdinal: $ordinal,
                occurredAt:  $this->clock->now(),
                fact:        $fact,
            );
        }

        return $events;
    }

    /**
     * @param  string[]  $objectIds  IDs of objects to remove
     * @param  int       $ordinal    timeline position of the removal
     * @return SemanticEvent[]
     */
    public function removals(array $objectIds, int $ordinal): array
    {
        return array_map(
            fn(string $id) => new WorldObjectRemovedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "object:{$id}",
                shotOrdinal: $ordinal,
                occurredAt:  $this->clock->now(),
                objectId:    $id,
            ),
            $objectIds,
        );
    }
}
