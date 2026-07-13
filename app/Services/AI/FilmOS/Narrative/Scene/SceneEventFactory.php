<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\CameraConfiguredEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\SceneNodePlacedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\SceneNodeRemovedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\SceneRelationEstablishedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;
use Illuminate\Support\Str;

/**
 * Translates scene composition intent into SemanticEvents for the Timeline.
 *
 * The only class permitted to create Scene-domain events.
 * Does not know WorldObject, GoalNode, or Planning domain.
 */
final class SceneEventFactory
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * Produces events for a single shot's scene composition.
     *
     * @param  SceneNode[]          $nodes      nodes to place in the scene
     * @param  SceneRelation[]      $relations  semantic relationships to establish
     * @param  CameraConfiguration  $camera     camera setup for this shot
     * @param  int                  $ordinal    shot ordinal (0-based)
     * @return SemanticEvent[]
     */
    public function setupShot(
        array               $nodes,
        array               $relations,
        CameraConfiguration $camera,
        int                 $ordinal,
    ): array {
        $now    = $this->clock->now();
        $events = [];

        foreach ($nodes as $node) {
            $events[] = new SceneNodePlacedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "scene_node:{$node->id}",
                shotOrdinal: $ordinal,
                occurredAt:  $now,
                node:        $node,
            );
        }

        foreach ($relations as $relation) {
            $events[] = new SceneRelationEstablishedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "scene_relation:{$relation->fromId}:{$relation->toId}",
                shotOrdinal: $ordinal,
                occurredAt:  $now,
                relation:    $relation,
            );
        }

        $events[] = new CameraConfiguredEvent(
            eventId:     (string) Str::ulid(),
            aggregateId: "camera:shot_{$ordinal}",
            shotOrdinal: $ordinal,
            occurredAt:  $now,
            camera:      $camera,
        );

        return $events;
    }

    /**
     * @param  string[]  $nodeIds  IDs of nodes to remove from the scene
     * @param  int       $ordinal  shot ordinal of the removal
     * @return SemanticEvent[]
     */
    public function removeNodes(array $nodeIds, int $ordinal): array
    {
        $now = $this->clock->now();

        return array_map(
            fn(string $id) => new SceneNodeRemovedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "scene_node:{$id}",
                shotOrdinal: $ordinal,
                occurredAt:  $now,
                nodeId:      $id,
            ),
            $nodeIds,
        );
    }
}
