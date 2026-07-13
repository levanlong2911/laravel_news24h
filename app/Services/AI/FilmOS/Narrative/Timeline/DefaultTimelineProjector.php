<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;

final class DefaultTimelineProjector implements TimelineProjector
{
    /** @param ProjectionHandler[] $handlers */
    public function __construct(array $handlers)
    {
        usort($handlers, static fn(ProjectionHandler $a, ProjectionHandler $b) => $a->priority() <=> $b->priority());
        $this->handlers = $handlers;
    }

    /** @var ProjectionHandler[] */
    private readonly array $handlers;

    public function project(SemanticTimeline $timeline, ?int $upToOrdinal = null): NarrativeState
    {
        $startMs = microtime(true) * 1000;

        $builder = new NarrativeStateBuilder();
        $context = new ProjectionContext(
            builder:      $builder,
            upToOrdinal:  $upToOrdinal,
        );

        $eventCount  = 0;
        $lastOrdinal = -1;

        foreach ($timeline->replay($upToOrdinal) as $event) {
            foreach ($this->handlers as $handler) {
                if ($handler->supports($event)) {
                    $handler->apply($event, $context);
                }
            }
            $eventCount++;
            $lastOrdinal = max($lastOrdinal, $event->shotOrdinal());
        }

        $metadata = new ProjectionMetadata(
            projectionTimeMs: microtime(true) * 1000 - $startMs,
            eventCount:       $eventCount,
            lastOrdinal:      $lastOrdinal,
            generatedAt:      time(),
        );

        return $builder->build(NarrativeState::SCHEMA_VERSION, $metadata);
    }
}
