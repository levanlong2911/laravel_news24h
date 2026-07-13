<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

final class InMemorySemanticTimeline implements SemanticTimeline
{
    /** @var SemanticEvent[] */
    private array $events = [];

    public function append(SemanticEvent $event): void
    {
        $this->events[] = $event;
    }

    public function events(): iterable
    {
        yield from $this->events;
    }

    public function replay(?int $upToOrdinal = null): iterable
    {
        foreach ($this->events as $event) {
            if ($upToOrdinal === null || $event->shotOrdinal() <= $upToOrdinal) {
                yield $event;
            }
        }
    }
}
