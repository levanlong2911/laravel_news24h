<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Timeline;

use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionContext;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full D0 pipeline:
 * GoalNodes → ShotPlannedEvents → Timeline → Projection → NarrativeState
 */
final class D0PipelineTest extends TestCase
{
    // ── Invariant: full pipeline produces correct NarrativeState ─────────────

    public function test_goal_nodes_project_into_narrative_state(): void
    {
        $goalNodes = [
            'hook'    => $this->leaf('hook',    'Camera pans across skyline'),
            'product' => $this->leaf('product', 'Hero holds product to camera'),
            'cta'     => $this->leaf('cta',     'Text overlay with call to action'),
        ];

        $timeline  = new InMemorySemanticTimeline();
        $recorder  = new TimelineRecorder($timeline);
        $factory   = new ShotPlannedEventFactory();
        $projector = new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]);

        $recorder->appendMany(...$factory->fromGoalNodes($goalNodes));

        $state = $projector->project($timeline);

        $this->assertCount(3, $state->story->allShots());
        $this->assertSame(3, $state->metadata->eventCount);
        $this->assertSame(2, $state->metadata->lastOrdinal);

        // First shot (ordinal 0) must be 'hook'
        $first = $state->story->shotAt(0);
        $this->assertSame('hook', $first?->shotId);
        $this->assertSame(0,      $first?->ordinal);
        $this->assertSame('leaf', $first?->goalType);
    }

    public function test_projection_order_matches_goal_node_iteration_order(): void
    {
        $goalNodes = [
            'a' => $this->leaf('a', 'First'),
            'b' => $this->leaf('b', 'Second'),
            'c' => $this->leaf('c', 'Third'),
        ];

        $timeline = new InMemorySemanticTimeline();
        (new TimelineRecorder($timeline))
            ->appendMany(...(new ShotPlannedEventFactory())->fromGoalNodes($goalNodes));

        $state = (new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]))->project($timeline);

        $shotIds = array_map(fn($shot) => $shot->shotId, array_values($state->story->allShots()));
        $this->assertSame(['a', 'b', 'c'], $shotIds);
    }

    // ── Invariant: ProjectionContext does NOT expose SemanticTimeline ─────────

    public function test_projection_context_has_no_timeline_property(): void
    {
        $reflection  = new \ReflectionClass(ProjectionContext::class);
        $propNames   = array_map(fn(\ReflectionProperty $p) => $p->getName(), $reflection->getProperties());

        $this->assertNotContains('timeline', $propNames,
            'ProjectionContext must not expose SemanticTimeline — handlers must not be able to call append().'
        );
    }

    // ── Invariant: Single Writer — only TimelineRecorder may write ────────────

    public function test_semantic_timeline_interface_has_no_public_append_accessible_from_context(): void
    {
        // ProjectionContext exposes only NarrativeStateBuilder.
        // Verify that a handler receiving $context cannot reach SemanticTimeline.
        $reflection = new \ReflectionClass(ProjectionContext::class);
        $types = [];
        foreach ($reflection->getProperties() as $prop) {
            $type = $prop->getType();
            if ($type instanceof \ReflectionNamedType) {
                $types[] = $type->getName();
            }
        }

        $this->assertNotContains(SemanticTimeline::class, $types);
    }

    private function leaf(string $id, string $description): GoalNode
    {
        return new GoalNode(
            id:          $id,
            description: $description,
            type:        GoalNodeType::LEAF,
            priority:    1.0,
        );
    }
}
