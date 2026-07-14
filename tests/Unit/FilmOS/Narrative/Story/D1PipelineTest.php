<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Story;

use App\Services\AI\FilmOS\Meaning\ContextualMeaningResolver;
use App\Services\AI\FilmOS\Narrative\NarrativeStructureBuilder;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Narrative\Story\StoryShot;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Planning\GoalDecomposer;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for D1 Story Memory:
 * beat flows as a TYPED VALUE from NarrativeStructureBuilder through
 * GoalNode → ShotPlannedEvent → StoryShot — never derived from identifiers.
 */
final class D1PipelineTest extends TestCase
{
    // ── Invariant: beat flows typed through the full pipeline ────────────────

    public function test_beat_flows_from_meaning_to_story_projection(): void
    {
        // Real upstream: facts → MeaningGraph → NarrativeGraph → GoalGraph
        $meaning   = (new ContextualMeaningResolver())->resolve(GoldenScenarioPipeline::facts(), 'travel_warning');
        $narrative = (new NarrativeStructureBuilder())->build($meaning);
        $goalGraph = (new GoalDecomposer())->decompose($narrative);

        $goalNodes = [];
        foreach ($goalGraph->leaves() as $leaf) {
            $goalNodes[$leaf->id] = $leaf;
        }

        $timeline = new InMemorySemanticTimeline();
        (new TimelineRecorder($timeline))
            ->appendMany(...(new ShotPlannedEventFactory())->fromGoalNodes($goalNodes));

        $state = (new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]))->project($timeline);

        // Every projected shot carries the beat its GoalNode carried — typed, not parsed
        foreach ($state->story->allShots() as $ordinal => $shot) {
            $this->assertInstanceOf(StoryBeat::class, $shot->beat,
                "shot at ordinal {$ordinal} must carry a typed StoryBeat");
            $this->assertSame($goalNodes[$shot->shotId]->beat, $shot->beat,
                'beat must be the exact enum instance passed through, never re-derived');
        }
    }

    // ── Invariant: beatOf() answers the PromptCompiler question ──────────────

    public function test_beat_of_returns_beat_at_ordinal(): void
    {
        $goalNodes = [
            'shot_hook'   => $this->leaf('shot_hook',   'Opening', StoryBeat::HOOK),
            'shot_payoff' => $this->leaf('shot_payoff', 'Closing', StoryBeat::PAYOFF),
        ];

        $state = $this->project($goalNodes);

        $this->assertSame(StoryBeat::HOOK,   $state->story->beatOf(0));
        $this->assertSame(StoryBeat::PAYOFF, $state->story->beatOf(1));
        $this->assertNull($state->story->beatOf(99), 'unknown ordinal → null');
    }

    // ── Invariant: fallback shots (no narrative structure) have null beat ─────

    public function test_shot_without_beat_projects_null_beat(): void
    {
        $state = $this->project([
            'shot_1' => $this->leaf('shot_1', 'Main shot', beat: null),
        ]);

        $this->assertTrue($state->story->hasShot(0));
        $this->assertNull($state->story->beatOf(0));
        $this->assertNull($state->story->shotAt(0)?->beat);
    }

    // ── StoryView query API ───────────────────────────────────────────────────

    public function test_story_view_queries(): void
    {
        $state = $this->project([
            'shot_hook' => $this->leaf('shot_hook', 'Opening', StoryBeat::HOOK),
        ]);

        $this->assertTrue($state->story->hasShot(0));
        $this->assertFalse($state->story->hasShot(1));

        $shot = $state->story->shotAt(0);
        $this->assertInstanceOf(StoryShot::class, $shot);
        $this->assertSame('shot_hook', $shot->shotId);
        $this->assertSame('Opening',   $shot->description);
        $this->assertSame('leaf',      $shot->goalType);

        $this->assertNull($state->story->shotAt(5));
        $this->assertCount(1, $state->story->allShots());
    }

    // ── latestShot convenience API ────────────────────────────────────────────

    public function test_latest_shot_returns_highest_ordinal(): void
    {
        $state = $this->project([
            'shot_hook'   => $this->leaf('shot_hook',   'Opening', StoryBeat::HOOK),
            'shot_payoff' => $this->leaf('shot_payoff', 'Closing', StoryBeat::PAYOFF),
        ]);

        $this->assertSame('shot_payoff', $state->story->latestShot()?->shotId);
        $this->assertSame(StoryBeat::PAYOFF, $state->story->latestShot()?->beat);
    }

    public function test_latest_shot_returns_null_for_empty_story(): void
    {
        $state = $this->project([]);

        $this->assertNull($state->story->latestShot());
    }

    // ── Invariant: shots() is keyed by ordinal ────────────────────────────────

    public function test_shots_are_keyed_by_ordinal(): void
    {
        $state = $this->project([
            'a' => $this->leaf('a', 'First'),
            'b' => $this->leaf('b', 'Second'),
        ]);

        $this->assertSame([0, 1], array_keys($state->story->allShots()));
        $this->assertSame('a', $state->story->allShots()[0]->shotId);
        $this->assertSame('b', $state->story->allShots()[1]->shotId);
    }

    // ── Invariant: GoalDecomposer passes beat, goalId is NOT the source ──────

    public function test_goal_decomposer_carries_beat_as_typed_value(): void
    {
        $meaning   = (new ContextualMeaningResolver())->resolve(GoldenScenarioPipeline::facts(), 'travel_warning');
        $narrative = (new NarrativeStructureBuilder())->build($meaning);
        $goalGraph = (new GoalDecomposer())->decompose($narrative);

        foreach ($goalGraph->leaves() as $leaf) {
            $this->assertInstanceOf(StoryBeat::class, $leaf->beat,
                "leaf {$leaf->id} must carry a typed beat from NarrativeStructureBuilder");
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, GoalNode> $goalNodes */
    private function project(array $goalNodes)
    {
        $timeline = new InMemorySemanticTimeline();
        (new TimelineRecorder($timeline))
            ->appendMany(...(new ShotPlannedEventFactory())->fromGoalNodes($goalNodes));

        return (new DefaultTimelineProjector([new ShotPlannedProjectionHandler()]))->project($timeline);
    }

    private function leaf(string $id, string $description, ?StoryBeat $beat = null): GoalNode
    {
        return new GoalNode(
            id:          $id,
            description: $description,
            type:        GoalNodeType::LEAF,
            priority:    1.0,
            beat:        $beat,
        );
    }
}
