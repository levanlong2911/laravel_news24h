<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\ShotPlannedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\Timeline\Projection\ProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;
use PHPUnit\Framework\TestCase;

final class DefaultTimelineProjectorTest extends TestCase
{
    // ── Invariant: Projector is pure — same input yields same result ──────────

    public function test_project_is_idempotent(): void
    {
        $timeline  = $this->timelineWith(3);
        $projector = new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]);

        $state1 = $projector->project($timeline);
        $state2 = $projector->project($timeline);

        // assertEquals (structural): each projection builds fresh StoryShot VOs,
        // so identity differs by design — purity means equal VALUES.
        $this->assertEquals($state1->story->allShots(), $state2->story->allShots());
        $this->assertSame($state1->metadata->eventCount, $state2->metadata->eventCount);
    }

    // ── Invariant: Metadata counts are accurate ───────────────────────────────

    public function test_project_counts_events_and_last_ordinal(): void
    {
        $timeline  = $this->timelineWith(3);
        $projector = new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]);

        $state = $projector->project($timeline);

        $this->assertSame(3, $state->metadata->eventCount);
        $this->assertSame(2, $state->metadata->lastOrdinal);
    }

    public function test_empty_timeline_produces_empty_state(): void
    {
        $projector = new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]);
        $state     = $projector->project(new InMemorySemanticTimeline());

        $this->assertSame(0, $state->metadata->eventCount);
        $this->assertSame(-1, $state->metadata->lastOrdinal);
        $this->assertEmpty($state->story->allShots());
    }

    // ── Invariant: upToOrdinal filters replayed events ────────────────────────

    public function test_project_with_up_to_ordinal_limits_events(): void
    {
        $timeline  = $this->timelineWith(4);
        $projector = new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]);

        $state = $projector->project($timeline, upToOrdinal: 1);

        $this->assertCount(2, $state->story->allShots());
        $this->assertSame(2, $state->metadata->eventCount);
        $this->assertSame(1, $state->metadata->lastOrdinal);
    }

    // ── Invariant: Handlers sorted by priority() ascending ───────────────────

    public function test_handlers_execute_in_priority_order(): void
    {
        $callOrder = [];

        $handlerB = $this->spyHandler(priority: 200, name: 'B', log: $callOrder);
        $handlerA = $this->spyHandler(priority: 100, name: 'A', log: $callOrder);

        // Pass B first (higher priority number) — constructor must sort
        $projector = new DefaultTimelineProjector([$handlerB, $handlerA]);

        $timeline = new InMemorySemanticTimeline();
        $timeline->append($this->event('e1', ordinal: 0));
        $projector->project($timeline);

        $this->assertSame(['A', 'B'], $callOrder);
    }

    // ── Invariant: schemaVersion matches NarrativeState::SCHEMA_VERSION ───────

    public function test_project_returns_correct_schema_version(): void
    {
        $state = (new DefaultTimelineProjector([]))->project(new InMemorySemanticTimeline());

        $this->assertSame(NarrativeState::SCHEMA_VERSION, $state->schemaVersion);
    }

    // ── Invariant: supports()=false → apply() never called ───────────────────

    public function test_handler_apply_not_called_when_supports_returns_false(): void
    {
        $applyCalls = 0;

        $rejectingHandler = new class($applyCalls) implements ProjectionHandler {
            public function __construct(private int &$calls) {}
            public function priority(): int { return 0; }
            public function supports(SemanticEvent $event): bool { return false; }
            public function apply(SemanticEvent $event, ProjectionContext $context): void
            {
                $this->calls++;
            }
        };

        $timeline = $this->timelineWith(3);
        (new DefaultTimelineProjector([$rejectingHandler]))->project($timeline);

        $this->assertSame(0, $applyCalls,
            'apply() must never be called when supports() returns false.'
        );
    }

    public function test_only_matching_handler_receives_apply(): void
    {
        $rejectedCalls = 0;
        $acceptedCalls = 0;

        $rejectingHandler = new class($rejectedCalls) implements ProjectionHandler {
            public function __construct(private int &$calls) {}
            public function priority(): int { return 0; }
            public function supports(SemanticEvent $event): bool { return false; }
            public function apply(SemanticEvent $event, ProjectionContext $context): void { $this->calls++; }
        };

        $acceptingHandler = new class($acceptedCalls) implements ProjectionHandler {
            public function __construct(private int &$calls) {}
            public function priority(): int { return 1; }
            public function supports(SemanticEvent $event): bool { return true; }
            public function apply(SemanticEvent $event, ProjectionContext $context): void { $this->calls++; }
        };

        $timeline = $this->timelineWith(2);
        (new DefaultTimelineProjector([$rejectingHandler, $acceptingHandler]))->project($timeline);

        $this->assertSame(0, $rejectedCalls, 'Rejecting handler must not receive apply().');
        $this->assertSame(2, $acceptedCalls, 'Accepting handler must receive apply() once per event.');
    }

    // ── Invariant: project() does not mutate the timeline ────────────────────

    public function test_project_does_not_append_events_to_timeline(): void
    {
        $timeline  = $this->timelineWith(3);
        $projector = new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]);

        $countBefore = iterator_count($timeline->events());
        $projector->project($timeline);
        $countAfter = iterator_count($timeline->events());

        $this->assertSame($countBefore, $countAfter,
            'project() must not append events to the timeline — projector is a pure read operation.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function timelineWith(int $count): InMemorySemanticTimeline
    {
        $timeline = new InMemorySemanticTimeline();
        for ($i = 0; $i < $count; $i++) {
            $timeline->append($this->event("e{$i}", ordinal: $i));
        }
        return $timeline;
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

    private function spyHandler(int $priority, string $name, array &$log): ProjectionHandler
    {
        return new class($priority, $name, $log) implements ProjectionHandler {
            private array $log;

            public function __construct(
                private int    $prio,
                private string $name,
                array          &$log,
            ) {
                $this->log = &$log;
            }

            public function priority(): int { return $this->prio; }

            public function supports(SemanticEvent $event): bool { return true; }

            public function apply(SemanticEvent $event, ProjectionContext $context): void
            {
                $this->log[] = $this->name;
            }
        };
    }
}
