<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Timeline\Bridge;

use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\ShotPlannedEvent;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

final class ShotPlannedEventFactoryTest extends TestCase
{
    // ── Invariant: empty input → empty output ─────────────────────────────────

    public function test_empty_goal_nodes_returns_empty_array(): void
    {
        $this->assertEmpty((new ShotPlannedEventFactory())->fromGoalNodes([]));
    }

    // ── Invariant: ordinals are 0-based and sequential ───────────────────────

    public function test_ordinals_are_sequential_starting_from_zero(): void
    {
        $events = (new ShotPlannedEventFactory())->fromGoalNodes([
            'hook' => $this->leafNode('hook', 'Hook shot'),
            'main' => $this->leafNode('main', 'Main shot'),
            'cta'  => $this->leafNode('cta',  'CTA shot'),
        ]);

        $this->assertSame(0, $events[0]->shotOrdinal());
        $this->assertSame(1, $events[1]->shotOrdinal());
        $this->assertSame(2, $events[2]->shotOrdinal());
    }

    // ── Invariant: aggregateId follows "shot:{shotId}" format ─────────────────

    public function test_aggregate_id_uses_shot_prefix(): void
    {
        $events = (new ShotPlannedEventFactory())->fromGoalNodes([
            'my_shot' => $this->leafNode('my_shot', 'Some shot'),
        ]);

        $this->assertSame('shot:my_shot', $events[0]->aggregateId());
    }

    // ── Invariant: eventId is a non-empty ULID string ────────────────────────

    public function test_event_id_is_non_empty(): void
    {
        $events = (new ShotPlannedEventFactory())->fromGoalNodes([
            'shot_a' => $this->leafNode('shot_a', 'A'),
        ]);

        $this->assertNotEmpty($events[0]->eventId());
    }

    // ── Invariant: event fields map correctly from GoalNode ──────────────────

    public function test_event_carries_shot_id_goal_type_and_description(): void
    {
        $events = (new ShotPlannedEventFactory())->fromGoalNodes([
            'hero_entrance' => $this->leafNode('hero_entrance', 'Hero walks into frame'),
        ]);

        $event = $events[0];
        $this->assertSame('hero_entrance', $event->shotId);
        $this->assertSame('leaf', $event->goalType);
        $this->assertSame('Hero walks into frame', $event->description);
    }

    // ── Invariant: returns ShotPlannedEvent instances ─────────────────────────

    public function test_returns_shot_planned_events(): void
    {
        $events = (new ShotPlannedEventFactory())->fromGoalNodes([
            's1' => $this->leafNode('s1', 'Shot 1'),
        ]);

        $this->assertInstanceOf(ShotPlannedEvent::class, $events[0]);
    }

    private function leafNode(string $id, string $description): GoalNode
    {
        return new GoalNode(
            id:          $id,
            description: $description,
            type:        GoalNodeType::LEAF,
            priority:    1.0,
        );
    }
}
