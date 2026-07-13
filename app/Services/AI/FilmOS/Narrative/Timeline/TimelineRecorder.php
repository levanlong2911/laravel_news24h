<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

final class TimelineRecorder
{
    public function __construct(
        private readonly SemanticTimeline $timeline,
    ) {}

    public function append(SemanticEvent $event): void
    {
        $this->timeline->append($event);
    }

    /** Append multiple events in one call.
     *  Used by ShotPlannedEventFactory which produces a batch from GoalNode[]. */
    public function appendMany(SemanticEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->timeline->append($event);
        }
    }
}
