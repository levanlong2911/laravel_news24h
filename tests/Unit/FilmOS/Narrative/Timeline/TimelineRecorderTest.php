<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\ShotPlannedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use PHPUnit\Framework\TestCase;

final class TimelineRecorderTest extends TestCase
{
    // ── Invariant: Recorder is the sole write path ────────────────────────────

    public function test_append_delegates_to_timeline(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);
        $event    = $this->event('e1', ordinal: 0);

        $recorder->append($event);

        $this->assertSame([$event], iterator_to_array($timeline->events()));
    }

    // ── Invariant: appendMany preserves insertion order ───────────────────────

    public function test_append_many_preserves_order(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);

        $a = $this->event('e1', ordinal: 0);
        $b = $this->event('e2', ordinal: 1);
        $c = $this->event('e3', ordinal: 2);

        $recorder->appendMany($a, $b, $c);

        $this->assertSame([$a, $b, $c], iterator_to_array($timeline->events()));
    }

    public function test_append_many_with_zero_events_does_nothing(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);

        $recorder->appendMany();

        $this->assertEmpty(iterator_to_array($timeline->events()));
    }

    private function event(string $eventId, int $ordinal): ShotPlannedEvent
    {
        return new ShotPlannedEvent(
            eventId:     $eventId,
            aggregateId: "shot:shot_{$ordinal}",
            shotOrdinal: $ordinal,
            occurredAt:  time(),
            shotId:      "shot_{$ordinal}",
            goalType:    'leaf',
            description: "Shot {$ordinal}",
        );
    }
}
