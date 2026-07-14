<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Bridge;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\ShotPlannedEvent;
use App\Services\AI\FilmOS\Planning\GoalNode;
use Illuminate\Support\Str;

/** Translates GoalNode[] (Planning domain) into ShotPlannedEvent[] (Narrative domain).
 *  The only class permitted to know both Planning and Timeline. */
final class ShotPlannedEventFactory
{
    /**
     * @param  GoalNode[]  $goalNodes  keyed by shotId (as produced by GoalDecomposer)
     * @return ShotPlannedEvent[]
     */
    public function fromGoalNodes(array $goalNodes): array
    {
        $events  = [];
        $ordinal = 0;

        foreach ($goalNodes as $shotId => $node) {
            $events[] = new ShotPlannedEvent(
                eventId:     (string) Str::ulid(),
                aggregateId: "shot:{$shotId}",
                shotOrdinal: $ordinal,
                occurredAt:  time(),
                shotId:      $shotId,
                goalType:    $node->type->value,
                description: $node->description,
                beat:        $node->beat,          // pass-through — factory has no beat logic
                endingFrame: $node->endingFrame,   // pass-through — same rule
            );
            $ordinal++;
        }

        return $events;
    }
}
