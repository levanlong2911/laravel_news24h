<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Performance;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Performance\CharacterPerformance;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceChannel;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceCue;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDirectedHandler;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceEventFactory;
use App\Services\AI\FilmOS\Narrative\Performance\PerformanceIntent;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Performance Layer:
 * directPerformance() → Timeline → Projection → NarrativeState::$performance
 */
final class PerformancePipelineTest extends TestCase
{
    // ── Invariant: the acting design materializes into the projection ─────────

    public function test_directed_performance_appears_in_narrative_state(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance(
                characterId: 'qb',
                ordinal:     2,
                intent:      new PerformanceIntent('suppress fear', motivation: 'does not want teammates to see doubt'),
                cues: [
                    new PerformanceCue('holds breath',        PerformanceChannel::BREATH),
                    new PerformanceCue('jaw tightens',        PerformanceChannel::FACE),
                    new PerformanceCue('eyes lock downfield', PerformanceChannel::GAZE),
                    new PerformanceCue('commits to the throw'),   // no single channel — nullable by design
                ],
            ),
        ]));

        $performance = $projector->project($timeline)->performance;
        $qb = $performance->performanceOf('qb', 2);

        $this->assertNotNull($qb);
        $this->assertSame('suppress fear', $qb->intent?->intent);
        $this->assertSame('does not want teammates to see doubt', $qb->intent?->motivation);
        $this->assertCount(4, $qb->cues);
        $this->assertSame(PerformanceChannel::BREATH, $qb->cues[0]->channel);
        $this->assertNull($qb->cues[3]->channel, 'cue without a single channel stays untyped — never forced');
    }

    // ── Invariant: cue array order IS temporal order ──────────────────────────

    public function test_cue_order_is_preserved_as_temporal_sequence(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance('qb', 0, cues: [
                new PerformanceCue('hesitates'),
                new PerformanceCue('half breath', PerformanceChannel::BREATH),
                new PerformanceCue('throws'),
            ]),
        ]));

        $cues = $projector->project($timeline)->performance->performanceOf('qb', 0)?->cues ?? [];

        $this->assertSame(
            ['hesitates', 'half breath', 'throws'],
            array_map(fn(PerformanceCue $c) => $c->description, $cues),
            'array order = temporal order inside the shot — the anti-keyframe invariant'
        );
    }

    // ── Invariant: NO persistence — acting is per-shot behavior ──────────────

    public function test_performance_does_not_persist_to_other_ordinals(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance('qb', 0, new PerformanceIntent('false confidence')),
        ]));

        $performance = $projector->project($timeline)->performance;

        $this->assertNotNull($performance->performanceOf('qb', 0));
        $this->assertNull($performance->performanceOf('qb', 1),
            'unlike D2 emotion, acting NEVER persists — absence means "no direction", not "repeat"');
    }

    // ── performancesAt: all characters directed in one shot ──────────────────

    public function test_performances_at_returns_all_characters_of_ordinal(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance('qb',       1, new PerformanceIntent('suppress fear')),
            new CharacterPerformance('receiver', 1, new PerformanceIntent('quiet readiness')),
            new CharacterPerformance('qb',       2, new PerformanceIntent('release')),
        ]));

        $atShot1 = $projector->project($timeline)->performance->performancesAt(1);

        $this->assertCount(2, $atShot1);
        $this->assertSame('suppress fear',   $atShot1['qb']->intent?->intent);
        $this->assertSame('quiet readiness', $atShot1['receiver']->intent?->intent);
    }

    // ── Invariant: one design per production — last-write-wins ───────────────

    public function test_duplicate_design_is_last_write_wins(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance('qb', 0, new PerformanceIntent('first')),
        ]));
        $bootstrapper->directPerformance(new PerformanceDesign([
            new CharacterPerformance('qb', 0, new PerformanceIntent('second')),
        ]));

        $performance = $projector->project($timeline)->performance;

        $this->assertSame('second', $performance->performanceOf('qb', 0)?->intent?->intent);
    }

    // ── A production without acting direction is valid ────────────────────────

    public function test_missing_design_yields_empty_view(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $performance = $projector->project($timeline)->performance;

        $this->assertNull($performance->performanceOf('qb', 0));
        $this->assertSame([], $performance->performancesAt(0));
        $this->assertSame([], $performance->allPerformances());
    }

    // ── Event: BASELINE ordinal + film-workflow name ──────────────────────────

    public function test_performance_directed_event_uses_baseline_ordinal(): void
    {
        $factory = new PerformanceEventFactory(new SystemClock());
        $event   = $factory->directed(new PerformanceDesign());

        $this->assertSame(TimelineOrdinal::BASELINE, $event->shotOrdinal());
        $this->assertSame('performance:default', $event->aggregateId());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector, NarrativeBootstrapper} */
    private function buildStack(): array
    {
        $timeline = new InMemorySemanticTimeline();
        $clock    = new SystemClock();

        $projector = new DefaultTimelineProjector(handlers: [
            new ShotPlannedProjectionHandler(),
            new PerformanceDirectedHandler(),
        ]);

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:       new WorldEventFactory($clock),
            shotFactory:        new ShotPlannedEventFactory(),
            sceneFactory:       new SceneEventFactory($clock),
            characterFactory:   new CharacterEventFactory($clock),
            productionFactory:  new ProductionEventFactory($clock),
            performanceFactory: new PerformanceEventFactory($clock),
            recorder:           new TimelineRecorder($timeline),
        );

        return [$timeline, $projector, $bootstrapper];
    }
}
