<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Timeline\Events\ShotPlannedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use PHPUnit\Framework\TestCase;

final class InMemorySemanticTimelineTest extends TestCase
{
    // ── Invariant: append-only, insertion order preserved ────────────────────

    public function test_append_stores_events_in_insertion_order(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $a = $this->event('e1', ordinal: 0);
        $b = $this->event('e2', ordinal: 1);

        $timeline->append($a);
        $timeline->append($b);

        $this->assertSame([$a, $b], iterator_to_array($timeline->events()));
    }

    // ── Invariant: replay(null) yields every event ────────────────────────────

    public function test_replay_null_returns_all_events(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $a = $this->event('e1', ordinal: 0);
        $b = $this->event('e2', ordinal: 5);

        $timeline->append($a);
        $timeline->append($b);

        $this->assertSame([$a, $b], iterator_to_array($timeline->replay(null)));
    }

    // ── Invariant: replay($n) yields only events with shotOrdinal ≤ $n ────────

    public function test_replay_filters_events_above_ordinal(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $a = $this->event('e1', ordinal: 0);
        $b = $this->event('e2', ordinal: 1);
        $c = $this->event('e3', ordinal: 2);

        $timeline->append($a);
        $timeline->append($b);
        $timeline->append($c);

        $result = iterator_to_array($timeline->replay(1));

        $this->assertSame([$a, $b], $result);
        $this->assertNotContains($c, $result);
    }

    public function test_replay_with_zero_ordinal_returns_only_first_event(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $a = $this->event('e1', ordinal: 0);
        $b = $this->event('e2', ordinal: 1);

        $timeline->append($a);
        $timeline->append($b);

        $this->assertSame([$a], iterator_to_array($timeline->replay(0)));
    }

    // ── Invariant: replay returns Generator (streaming, not array copy) ───────

    public function test_replay_returns_generator(): void
    {
        $timeline = new InMemorySemanticTimeline();

        $this->assertInstanceOf(\Generator::class, $timeline->replay());
    }

    // ── Invariant: empty timeline produces empty iterable ────────────────────

    public function test_empty_timeline_replay_returns_empty(): void
    {
        $this->assertEmpty(iterator_to_array((new InMemorySemanticTimeline())->replay()));
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
